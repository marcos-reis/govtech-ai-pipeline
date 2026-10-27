"""
GovTech Pipeline - Ephemeral Python Worker & OS Memory Reclamation
Reference Implementation from app/worker.py and app/orchestrator.py
"""

import os
import gc
import ctypes
import logging
import threading
import multiprocessing as mp
from queue import Empty

logger = logging.getLogger("govtech.worker")


def cleanup_memory():
    """
    Executes aggressive RAM and VRAM reclamation.
    Triggers Python GC, purges PyTorch/CUDA cache, and directly invokes
    glibc's malloc_trim(0) on Linux to release free heap pages back to the OS.
    """
    gc.collect()

    # 1. Purge PyTorch & GPU cache if CUDA is active
    try:
        import torch
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
            torch.cuda.ipc_collect()
    except Exception:
        pass

    # 2. Force Linux Kernel Heap Trimming via GNU C Library
    try:
        ctypes.CDLL("libc.so.6").malloc_trim(0)
    except Exception:
        pass


def _worker_loop(task_queue: mp.Queue, idle_timeout: int = 60):
    """
    Worker execution loop inside the dedicated isolated process.
    Processes RAG document ingestion sequentially.
    When idle for > idle_timeout seconds, terminates the process to return
    100% of allocated RAM/VRAM to the host operating system.
    """
    pid = os.getpid()
    logger.info(f"[Worker PID:{pid}] RAG Worker spawned. Awaiting tasks (Idle timeout: {idle_timeout}s)...")

    # Heavy machine learning libraries are imported STRICTLY inside the child worker process
    from app.orchestrator import process_licitacao_pipeline

    while True:
        try:
            task = task_queue.get(timeout=idle_timeout)
        except Empty:
            logger.info(f"[Worker PID:{pid}] Worker idle for >{idle_timeout}s. Exiting to reclaim 100% OS RAM.")
            break
        except Exception as e:
            logger.warning(f"[Worker PID:{pid}] Exception reading from queue: {e}")
            break

        if task is None or task == "STOP":
            logger.info(f"[Worker PID:{pid}] Stop signal received. Shutting down worker.")
            break

        licitacao_id = task
        logger.info(f"[Worker PID:{pid}] Processing tender ID: {licitacao_id}")
        
        try:
            process_licitacao_pipeline(licitacao_id=licitacao_id)
        except Exception as exc:
            logger.error(f"[Worker PID:{pid}] Error processing tender {licitacao_id}: {exc}", exc_info=True)
        finally:
            # Reclaim heap immediately after each document
            cleanup_memory()

    cleanup_memory()
    logger.info(f"[Worker PID:{pid}] Worker process exited cleanly.")


class WorkerManager:
    """
    Singleton Manager for the Ephemeral Worker Process Lifecycle.
    Implements Lazy Process Spawning and Graceful Shutdown.
    """
    _instance = None
    _lock = threading.Lock()

    def __init__(self):
        self._ctx = mp.get_context("spawn")
        self._process: mp.Process | None = None
        self._queue: mp.Queue | None = None
        self._state_lock = threading.Lock()

    @classmethod
    def get_instance(cls) -> "WorkerManager":
        with cls._lock:
            if cls._instance is None:
                cls._instance = WorkerManager()
            return cls._instance

    def _ensure_worker_running(self):
        with self._state_lock:
            if self._process is None or not self._process.is_alive():
                logger.info("[WorkerManager] Spawning new isolated worker process...")
                self._queue = self._ctx.Queue()
                self._process = self._ctx.Process(
                    target=_worker_loop,
                    args=(self._queue, 60),
                    daemon=True
                )
                self._process.start()
                logger.info(f"[WorkerManager] Worker process active with PID: {self._process.pid}")

    def submit_task(self, licitacao_id: int):
        """Submits a tender for asynchronous ingestion, starting the worker on-demand."""
        self._ensure_worker_running()
        with self._state_lock:
            if self._queue is not None:
                self._queue.put(licitacao_id)
