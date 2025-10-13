<?php

namespace Martingalian\Core;

use Illuminate\Support\Facades\Route;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/martingalian.php' => config_path('martingalian.php'),
        ]);

        Model::automaticallyEagerLoadRelationships();
        Model::unguard();

        AccountBalanceHistory::observe(AccountBalanceHistoryObserver::class);
        Account::observe(AccountObserver::class);
        ApplicationLog::observe(ApplicationLogObserver::class);
        ApiRequestLog::observe(ApiRequestLogObserver::class);
        ApiSnapshot::observe(ApiSnapshotObserver::class);
        ApiSystem::observe(ApiSystemObserver::class);
        BaseAssetMapper::observe(BaseAssetMapperObserver::class);
        Step::observe(StepObserver::class);
        ExchangeSymbol::observe(ExchangeSymbolObserver::class);
        Indicator::observe(IndicatorObserver::class);
        Order::observe(OrderObserver::class);
        Position::observe(PositionObserver::class);
        Quote::observe(QuoteObserver::class);
        ForbiddenHostname::observe(ForbiddenHostnameObserver::class);
        Symbol::observe(SymbolObserver::class);
        User::observe(UserObserver::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/martingalian.php',
            'martingalian'
        );
    }

    protected function registerSlowQueryListener(): void
    {
        DB::listen(function (QueryExecuted $query) {
            $threshold = (int) config('martingalian.slow_query_threshold_ms', 2500);
            if ($query->time <= $threshold) {
                return;
            }

            if (Str::contains(Str::lower($query->sql), 'slow_queries')) {
                return;
            }

            $bindings = $query->bindings;
            foreach ($bindings as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $bindings[$k] = $v->format('Y-m-d H:i:s');
                }
            }

            $sqlFull = $query->sql;
            foreach ($bindings as $binding) {
                $val = is_null($binding) ? 'NULL' : addslashes((string) $binding);
                $wrap = is_null($binding) ? 'NULL' : "'{$val}'";
                $sqlFull = preg_replace('/\?/', $wrap, $sqlFull, 1);
            }

            SlowQuery::create([
                'tick_id' => cache('current_tick_id'),
                'connection' => $query->connectionName,
                'time_ms' => (int) $query->time,
                'sql' => $query->sql,
                'sql_full' => $sqlFull,
                'bindings' => $bindings,
            ]);
        });
    }
}
