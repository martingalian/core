<?php

declare(strict_types=1);

namespace Martingalian\Core;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Martingalian\Core\Commands\DispatchStepsCommand;
use Martingalian\Core\Commands\SafeToRestartCommand;
use Martingalian\Core\Commands\UpdateRecvwindowSafetyDurationCommand;
use Martingalian\Core\Listeners\NotificationLogListener;
use Martingalian\Core\Models\Account;
use Martingalian\Core\Models\AccountBalanceHistory;
use Martingalian\Core\Models\ApiRequestLog;
use Martingalian\Core\Models\ApiSnapshot;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Models\ExchangeSymbol;
use Martingalian\Core\Models\ForbiddenHostname;
use Martingalian\Core\Models\Indicator;
use Martingalian\Core\Models\Martingalian;
use Martingalian\Core\Models\NotificationLog;
use Martingalian\Core\Models\Order;
use Martingalian\Core\Models\Position;
use Martingalian\Core\Models\SlowQuery;
use Martingalian\Core\Models\Step;
use Martingalian\Core\Models\Symbol;
use Martingalian\Core\Models\User;
use Martingalian\Core\Support\NotificationService;
use Martingalian\Core\Observers\AccountBalanceHistoryObserver;
use Martingalian\Core\Observers\AccountObserver;
use Martingalian\Core\Observers\ApiRequestLogObserver;
use Martingalian\Core\Observers\ApiSnapshotObserver;
use Martingalian\Core\Observers\ApiSystemObserver;
use Martingalian\Core\Observers\ModelLogObserver;
use Martingalian\Core\Observers\ExchangeSymbolObserver;
use Martingalian\Core\Observers\ForbiddenHostnameObserver;
use Martingalian\Core\Observers\IndicatorObserver;
use Martingalian\Core\Observers\NotificationLogObserver;
use Martingalian\Core\Observers\OrderObserver;
use Martingalian\Core\Observers\PositionObserver;
use Martingalian\Core\Observers\StepObserver;
use Martingalian\Core\Observers\SymbolObserver;
use Martingalian\Core\Observers\UserObserver;

final class CoreServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchStepsCommand::class,
                SafeToRestartCommand::class,
                UpdateRecvwindowSafetyDurationCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'martingalian');

        // Load API routes with /api prefix
        $this->app->router->group([
            'prefix' => 'api',
            'middleware' => ['web'],
        ], function ($router) {
            require __DIR__.'/../routes/api.php';
        });

        $this->publishes([
            __DIR__.'/../config/martingalian.php' => config_path('martingalian.php'),
        ]);

        Model::automaticallyEagerLoadRelationships();
        Model::unguard();

        // Register NotificationLogListener as event subscriber
        Event::subscribe(NotificationLogListener::class);

        AccountBalanceHistory::observe(AccountBalanceHistoryObserver::class);
        Account::observe(AccountObserver::class);
        ApiRequestLog::observe(ApiRequestLogObserver::class);
        ApiSnapshot::observe(ApiSnapshotObserver::class);
        ApiSystem::observe(ApiSystemObserver::class);
        Step::observe(StepObserver::class);
        ExchangeSymbol::observe(ExchangeSymbolObserver::class);
        Indicator::observe(IndicatorObserver::class);
        NotificationLog::observe(NotificationLogObserver::class);
        Order::observe(OrderObserver::class);
        Position::observe(PositionObserver::class);
        ForbiddenHostname::observe(ForbiddenHostnameObserver::class);
        Symbol::observe(SymbolObserver::class);
        User::observe(UserObserver::class);

        // Register slow query listener
        $this->registerSlowQueryListener();
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
            $threshold = (int) config('martingalian.slow_query_threshold_ms', 5000);
            if ($query->time <= $threshold) {
                return;
            }

            if (Str::contains(Str::lower($query->sql), 'slow_queries')) {
                return;
            }

            // Skip during migrations when slow_queries table may not exist yet
            if (! \Schema::hasTable('slow_queries')) {
                return;
            }

            $bindings = $query->bindings;
            foreach ($bindings as $k => $v) {
                if ($v instanceof DateTimeInterface) {
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

            // Send notification to admin about slow query
            NotificationService::send(
                user: Martingalian::admin(),
                canonical: 'slow_query_detected',
                referenceData: [
                    'sql_full' => $sqlFull,
                    'time_ms' => (int) $query->time,
                    'connection' => $query->connectionName,
                    'threshold_ms' => $threshold,
                ]
            );
        });
    }
}
