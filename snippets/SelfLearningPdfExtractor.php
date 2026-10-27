<?php

namespace App\Services\Extraction\Snippets;

use App\Models\Licitacao;
use App\Models\PerfilEntidade;
use App\Models\LicitacaoItem;
use App\Services\Extraction\PdfTextParser;
use App\Services\Extraction\LlmService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GovTech Pipeline - Self-Learning PDF Item Extractor (Direct Pass Engine)
 * Reference implementation from ExtractLicitacaoItensPdfJob.php
 */
class SelfLearningPdfExtractor
{
    public function extractItems(
        Licitacao $licitacao,
        $file,
        PdfTextParser $parser,
        LlmService $llmService
    ): bool {
        $cnpjClean = preg_replace('/\D+/', '', $licitacao->cnpj ?? '');
        
        // 1. Fetch or initialize the learned Entity Profile for this CNPJ
        $perfil = PerfilEntidade::firstOrCreate(
            ['cnpj' => $cnpjClean],
            ['entidade' => $licitacao->orgao_nome ?? 'Public Entity']
        );

        $localPath = $parser->downloadTemporary($file->storage_filename);
        $pagesCount = $parser->getPagesCount($localPath);

        $startPage = null;
        $endPage = null;
        $hasDirectPass = false;

        $ancorasInicio = $perfil->ancoras_inicio ?? [];
        $ancorasFim = $perfil->ancoras_fim ?? [];

        // 2. DIRECT PASS (Zero-Token Discovery using learned template anchors)
        if (!empty($ancorasInicio)) {
            Log::info("SelfLearningPdfExtractor: [Direct Pass] Scanning in-memory strings for CNPJ [{$cnpjClean}]...");
            
            for ($p = 1; $p <= $pagesCount; $p++) {
                $pageText = $parser->extractPageText($localPath, $p);
                $pageNormalized = Str::lower(Str::ascii($pageText));

                foreach ($ancorasInicio as $ancora) {
                    if (str_contains($pageNormalized, Str::lower(Str::ascii($ancora)))) {
                        $startPage = $p;
                        break 2;
                    }
                }
            }

            if ($startPage !== null && !empty($ancorasFim)) {
                for ($p = $startPage; $p <= $pagesCount; $p++) {
                    $pageText = $parser->extractPageText($localPath, $p);
                    $pageNormalized = Str::lower(Str::ascii($pageText));

                    foreach ($ancorasFim as $ancora) {
                        if (str_contains($pageNormalized, Str::lower(Str::ascii($ancora)))) {
                            $endPage = $p;
                            break 2;
                        }
                    }
                }
            }

            if ($startPage !== null && $endPage !== null) {
                $hasDirectPass = true;
                Log::info("SelfLearningPdfExtractor: [Direct Pass] Success! Window found: pp. {$startPage}-{$endPage} (0 tokens used).");
            }
        }

        // 3. SEQUENTIAL DISCOVERY (For new entities, with local low-signal filter and auto-learning)
        if (!$hasDirectPass) {
            Log::info("SelfLearningPdfExtractor: Starting Sequential Discovery with Local Low-Signal Filter...");

            for ($p = 1; $p <= $pagesCount; $p++) {
                $pageText = $parser->extractPageText($localPath, $p);
                $pageUpper = Str::upper(Str::ascii($pageText));

                // A. Local Structural Filter: Reject non-tabular pages immediately
                $structuralKeywords = ['QUANTIDADE', 'PREÇO', 'VALOR', 'ITEM', 'DESCRIÇÃO', 'LOTE'];
                $hasKeyword = false;
                foreach ($structuralKeywords as $kw) {
                    if (str_contains($pageUpper, $kw)) {
                        $hasKeyword = true;
                        break;
                    }
                }

                if (!$hasKeyword) {
                    continue; // Skip non-tabular pages without calling the LLM
                }

                // B. LLM Boundary Classification
                $classification = $llmService->classifyPage($pageText);
                $data = $classification['data'] ?? [];

                if ($data['is_start_of_list'] ?? false) {
                    if ($startPage === null) {
                        $startPage = $p;

                        // Auto-learn start anchor
                        if (!empty($data['detected_start_anchor'])) {
                            $current = $perfil->ancoras_inicio ?? [];
                            if (!in_array($data['detected_start_anchor'], $current)) {
                                $current[] = $data['detected_start_anchor'];
                                $perfil->update(['ancoras_inicio' => $current]);
                                Log::info("SelfLearningPdfExtractor: Learned new start anchor for CNPJ [{$cnpjClean}]: '{$data['detected_start_anchor']}'");
                            }
                        }
                    }
                }

                if ($data['is_end_of_list'] ?? false) {
                    $endPage = $p;

                    // Auto-learn end anchor
                    if (!empty($data['detected_end_anchor'])) {
                        $current = $perfil->ancoras_fim ?? [];
                        if (!in_array($data['detected_end_anchor'], $current)) {
                            $current[] = $data['detected_end_anchor'];
                            $perfil->update(['ancoras_fim' => $current]);
                            Log::info("SelfLearningPdfExtractor: Learned new end anchor for CNPJ [{$cnpjClean}]: '{$data['detected_end_anchor']}'");
                        }
                    }
                    break;
                }
            }
        }

        // 4. SURGICAL EXTRACTION & ATOMIC DEDUPLICATION
        if ($startPage !== null) {
            $endPage = $endPage ?? $startPage;
            
            $windowText = '';
            for ($p = $startPage; $p <= $endPage; $p++) {
                $windowText .= $parser->extractPageText($localPath, $p) . "\n--- PAGE {$p} ---\n";
            }

            $extraction = $llmService->extractItens($windowText);
            $items = $extraction['data']['items'] ?? [];

            if (!empty($items)) {
                DB::transaction(function () use ($licitacao, $file, $items) {
                    // A. Persist clean extracted PDF items
                    foreach ($items as $item) {
                        LicitacaoItem::updateOrCreate([
                            'licitacao_id' => $licitacao->id,
                            'numero_item' => (int) preg_replace('/\D+/', '', (string)($item['item_number'] ?? 0)),
                            'arquivo_id' => $file->id,
                        ], [
                            'descricao' => $item['description'] ?? 'Sem descrição',
                            'quantidade' => (float) ($item['quantity'] ?? 0),
                            'valor_estimado' => (float) ($item['estimated_unit_value'] ?? 0),
                            'origem' => 'IA_PDF',
                            'status_item' => 'RASCUNHO',
                        ]);
                    }

                    // B. Deactivate generic duplicate items originally imported from the API
                    LicitacaoItem::where('licitacao_id', $licitacao->id)
                        ->where('origem', 'INTEGRACAO_API')
                        ->update(['status_item' => 'IGNORADO_DUPLICADO']);
                });

                return true; // Early Stop triggers
            }
        }

        return false;
    }
}
