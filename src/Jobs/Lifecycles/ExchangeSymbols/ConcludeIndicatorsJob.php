<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Lifecycles\ExchangeSymbols;

use Log;
use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Exceptions\ExceptionParser;
use Martingalian\Core\Jobs\Models\ExchangeSymbol\ConcludeDirectionJob;
use Martingalian\Core\Jobs\Models\Indicator\QueryAllIndicatorsForSymbolsChunkJob;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\StepsDispatcher;
use Martingalian\Core\Models\TradeConfiguration;
use App\Support\NotificationService;
use App\Support\Throttler;
use Throwable;

/**
 * Concludes indicator directions for exchange symbols using batched API queries.
 * Scoped to a specific API system (exchange) for parallel processing.
 *
 * Optimization:
 * - Batches symbols into chunks for efficient bulk API queries
 * - Queries ALL indicators for multiple symbols in one request
 * - Stores results in indicator_histories table
 * - Creates individual conclusion jobs that read from table
 * - Reduces API calls from N (one per symbol) to ceil(N / chunk_size)
 */
final class ConcludeIndicatorsJob extends BaseQueueableJob
{
    public ExchangeSymbol $exchangeSymbol;

    protected ?int $apiSystemId;

    public function __construct(?int $apiSystemId = null)
    {
        $this->apiSystemId = $apiSystemId;
    }

    public function compute()
    {
        $stepId = $this->step->id ?? 'unknown';
        $startTime = microtime(true);
        Log::channel('jobs')->info("[COMPUTE-DETAIL] Step #{$stepId} | ConcludeIndicatorsJob | Starting compute()...");

        info_if('[ConcludeIndicatorsJob] Starting compute()...');
        info_if('[ConcludeIndicatorsJob] API System ID: '.($this->apiSystemId ?? 'null'));

        $query = ExchangeSymbol::query();

        // Scope to specific API system if provided
        if ($this->apiSystemId !== null) {
            $query->where('api_system_id', $this->apiSystemId);
            info_if('[ConcludeIndicatorsJob] Filtering by API system ID: '.$this->apiSystemId);
        }

        $queryStart = microtime(true);
        $exchangeSymbols = $query->get();
        $queryTime = round((microtime(true) - $queryStart) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE-DETAIL] Step #{$stepId} | ConcludeIndicatorsJob | Query exchange symbols: {$queryTime}ms | Found: ".$exchangeSymbols->count());
        info_if('[ConcludeIndicatorsJob] Found '.$exchangeSymbols->count().' exchange symbols');

        if ($exchangeSymbols->isEmpty()) {
            info_if('[ConcludeIndicatorsJob] No exchange symbols found, returning early');

            return ['message' => 'No exchange symbols found'];
        }

        // Get ALL timeframes from trade configuration
        $tradeConfig = TradeConfiguration::getDefault();
        $timeframes = $tradeConfig->indicator_timeframes;
        info_if('[ConcludeIndicatorsJob] All timeframes: '.json_encode($timeframes));

        // Get child_block_uuid from parent step
        // This UUID links the child workflow to this ConcludeIndicatorsJob
        $childBlockUuid = $this->step->child_block_uuid;
        info_if('[ConcludeIndicatorsJob] Child block UUID: '.$childBlockUuid);

        if (! $childBlockUuid) {
            info_if('[ConcludeIndicatorsJob] ERROR: No child_block_uuid provided by parent step');

            return ['error' => 'No child_block_uuid provided'];
        }

        // NEW APPROACH: Create ONE job per symbol per timeframe
        // This isolates each symbol so if one fails (e.g., not supported by Taapi),
        // it doesn't affect other symbols in the batch
        $batchJobsCount = 0;
        $jobsPerIndexBatch = config('martingalian.indicators.jobs_per_index_batch', 40);
        info_if('[ConcludeIndicatorsJob] Jobs per index batch (from config): '.$jobsPerIndexBatch);

        // Build array of steps for batch insert
        $buildArrayStart = microtime(true);
        $stepsToCreate = [];
        $now = now();

        // Iterate through all symbols
        foreach ($exchangeSymbols as $exchangeSymbol) {
            // For each symbol, create jobs for ALL timeframes
            foreach ($timeframes as $timeframe) {
                // Calculate index: each batch of $jobsPerIndexBatch jobs gets the same index
                // This creates sequential execution of batches while allowing parallelism within each batch
                $stepIndex = (int) floor($batchJobsCount / $jobsPerIndexBatch) + 1;

                // Get a random group for each step for load balancing
                $group = StepsDispatcher::getDispatchGroup();

                // Add to batch array instead of individual create
                $stepsToCreate[] = [
                    'class' => QueryAllIndicatorsForSymbolsChunkJob::class,
                    'queue' => 'indicators',
                    'block_uuid' => $childBlockUuid,
                    'group' => $group,
                    'state' => \Martingalian\Core\States\Pending::class,
                    'index' => $stepIndex,
                    'arguments' => json_encode([
                        'exchangeSymbolIds' => [$exchangeSymbol->id],
                        'timeframe' => $timeframe,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $batchJobsCount++;
            }
        }
        $buildArrayTime = round((microtime(true) - $buildArrayStart) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE-DETAIL] Step #{$stepId} | ConcludeIndicatorsJob | Build query jobs array: {$buildArrayTime}ms | Jobs: ".$batchJobsCount);

        // Batch insert all query jobs at once
        if (! empty($stepsToCreate)) {
            $insertStart = microtime(true);
            Step::insert($stepsToCreate);
            $insertTime = round((microtime(true) - $insertStart) * 1000, 2);
            Log::channel('jobs')->info("[COMPUTE-DETAIL] Step #{$stepId} | ConcludeIndicatorsJob | Batch insert query jobs: {$insertTime}ms | Jobs: ".count($stepsToCreate));
        }

        $totalIndexGroups = (int) ceil($batchJobsCount / $jobsPerIndexBatch);
        info_if('[ConcludeIndicatorsJob] Created '.$batchJobsCount.' query jobs (1 symbol per job) across '.count($timeframes).' timeframes');
        info_if('[ConcludeIndicatorsJob] Jobs distributed across '.$totalIndexGroups.' sequential index groups ('.$jobsPerIndexBatch.' jobs per group)');

        // Now create individual conclusion jobs for each symbol
        // All ConcludeDirectionJob use the next index after the last batch index
        // This ensures they wait for ALL batch jobs to complete but run in parallel with each other
        // These will read from indicator_histories table populated by batch jobs
        $concludeDirectionIndex = $totalIndexGroups + 1;
        info_if('[ConcludeIndicatorsJob] Creating conclusion jobs for '.$exchangeSymbols->count().' symbols (index='.$concludeDirectionIndex.')...');

        $buildConcludeStart = microtime(true);
        $concludeStepsToCreate = [];
        foreach ($exchangeSymbols as $exchangeSymbol) {
            // Get a random group for each step for load balancing
            $group = StepsDispatcher::getDispatchGroup();

            $concludeStepsToCreate[] = [
                'class' => ConcludeDirectionJob::class,
                'queue' => 'indicators',
                'block_uuid' => $childBlockUuid,
                'group' => $group,
                'state' => \Martingalian\Core\States\Pending::class,
                'index' => $concludeDirectionIndex,
                'arguments' => json_encode([
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $buildConcludeTime = round((microtime(true) - $buildConcludeStart) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE-DETAIL] Step #{$stepId} | ConcludeIndicatorsJob | Build conclude jobs array: {$buildConcludeTime}ms | Jobs: ".count($concludeStepsToCreate));

        // Batch insert all conclusion jobs at once
        if (! empty($concludeStepsToCreate)) {
            $insertConcludeStart = microtime(true);
            Step::insert($concludeStepsToCreate);
            $insertConcludeTime = round((microtime(true) - $insertConcludeStart) * 1000, 2);
            Log::channel('jobs')->info("[COMPUTE-DETAIL] Step #{$stepId} | ConcludeIndicatorsJob | Batch insert conclude jobs: {$insertConcludeTime}ms | Jobs: ".count($concludeStepsToCreate));
        }

        info_if('[ConcludeIndicatorsJob] Created '.$exchangeSymbols->count().' conclusion jobs (all with index='.$concludeDirectionIndex.' for parallel execution after batch jobs)');

        $result = [
            'total_symbols' => $exchangeSymbols->count(),
            'batch_jobs_created' => $batchJobsCount,
            'conclusion_jobs_created' => $exchangeSymbols->count(),
            'jobs_per_index_batch' => $jobsPerIndexBatch,
            'total_index_groups' => $totalIndexGroups,
        ];

        info_if('[ConcludeIndicatorsJob] Completed successfully: '.json_encode($result));

        $totalComputeTime = round((microtime(true) - $startTime) * 1000, 2);
        Log::channel('jobs')->info("[COMPUTE-DETAIL] Step #{$stepId} | ConcludeIndicatorsJob | TOTAL COMPUTE: {$totalComputeTime}ms");

        return $result;
    }

    public function resolveException(Throwable $e)
    {
        $symbolId = isset($this->exchangeSymbol) ? $this->exchangeSymbol->id : 'unknown';

        Throttler::using(NotificationService::class)
                ->withCanonical('conclude_indicators_error')
                ->execute(function () {
                    NotificationService::sendToAdmin(
                        message: "[{$symbolId}] - ConcludeIndicatorsJob lifecycle error - ".ExceptionParser::with($e)->friendlyMessage(),
                        title: "[S:{$this->step->id}] ".class_basename(self::class).' - Error',
                        deliveryGroup: 'exceptions'
                    );
                });
    }
}
