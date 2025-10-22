<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Martingalian\Core\Database\Seeders\SchemaSeeder1;

return new class extends Migration
{
    // FK'S BOOLEANS INTS/NUMERICS STRINGS ARRAYS/JSONS TEXTS DATETIMES
    public function up(): void
    {
        Schema::create('api_snapshots', function (Blueprint $table) {
            $table->id();

            // Polymorphic reference to owning model
            $table->string('responsable_type');
            $table->unsignedBigInteger('responsable_id');

            // Canonical key for grouping API responses
            $table->string('canonical');

            // Raw API response payload
            $table->json('api_response')->nullable();

            $table->timestamps();

            // Enforce one canonical snapshot per model
            $table->unique(
                ['responsable_type', 'responsable_id', 'canonical'],
                'unique_api_snapshot_per_canonical'
            );
        });

        Schema::create('debuggable_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('debuggable');
            $table->string('label')->nullable();
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('debuggables', function (Blueprint $table) {
            $table->id();
            $table->morphs('debuggable');

            $table->timestamps();
        });

        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exchange_symbol_id');
            $table->decimal('mark_price', 20, 8)->nullable();

            $table->timestamps();
        });

        Schema::create('steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('block_uuid');
            $table->string('type')->default('default');
            $table->string('state')->default('pending');
            $table->string('class')->nullable();
            $table->unsignedInteger('index')->nullable();
            $table->longText('response')->nullable(); // to store job results, JSON or plain text
            $table->text('error_message')->nullable(); // short error summary
            $table->longText('error_stack_trace')->nullable(); // full exception trace or context

            $table->string('relatable_type')->nullable()->index();
            $table->unsignedBigInteger('relatable_id')->nullable()->index();

            $table->uuid('child_block_uuid')->nullable();
            $table->string('execution_mode')->default('default');
            $table->unsignedTinyInteger('double_check')
                ->default(0)
                ->comment('0 => Not yet double checked at all, 1=First double check done, 2=No more double checks to do');

            $table->string('queue')->default('sync');
            $table->json('arguments')->nullable();
            $table->unsignedInteger('retries')->default(0);
            $table->timestamp('dispatch_after')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedBigInteger('duration')->default(0);

            $table->string('hostname')->nullable();
            $table->boolean('was_notified')->default(false);

            $table->timestamps();

            $table->index('state');
            $table->index('type');
            $table->index('block_uuid');
            $table->index('child_block_uuid');
            $table->index('dispatch_after');

            $table->index(['block_uuid', 'index']);
            $table->index(['block_uuid', 'type']);
            $table->index(['block_uuid', 'state']);
            $table->index(['type', 'state']);
        });

        Schema::create('indicators', function (Blueprint $table) {
            $table->id();

            $table->string('type')
                ->default('refresh-data')
                ->comment('The indicator group class. E.g.: refresh-data means the indicator will be used to query exchange symbols indicator data cronjobs');

            $table->boolean('is_active')->default(true);

            $table->boolean('is_apiable')->default(true)
                ->comment('Indicator will call the api or not');

            $table->string('canonical')
                ->unique()
                ->comment('Internal id that will be set on the indicator class for further results mapping');

            $table->json('parameters')
                ->nullable()
                ->comment('The parameters that will be passed to taapi. Just indicator parameters, not secret neither exchange parameters');

            $table->string('class')
                ->comment('The indicator class that will be used to instance and use the indicator');

            $table->timestamps();
        });

        Schema::create('trade_configuration', function (Blueprint $table) {
            $table->id();

            $table->boolean('is_default')
                ->default(false);

            $table->string('canonical')
                ->unique();

            $table->string('description');

            $table->unsignedInteger('least_timeframe_index_to_change_indicator')
                ->default(1)
                ->comment('Minimum array key index on the timeframe array to accept a direction change');

            $table->unsignedInteger('total_positions_short')
                ->default(1)
                ->comment('Max active positions SHORT');

            $table->unsignedInteger('total_positions_long')
                ->default(1)
                ->comment('Max active positions LONG');

            $table->decimal('position_margin_percentage_long', 5, 2)
                ->default(0.15)
                ->comment('The margin percentage that will be used on each LONG position');

            $table->decimal('position_margin_percentage_short', 5, 2)
                ->default(0.15)
                ->comment('The margin percentage that will be used on each SHORT position');

            $table->decimal('profit_percentage', 6, 3)->nullable()
                ->comment('The profit percentage');

            $table->unsignedInteger('fast_trade_position_duration_seconds')
                ->default(600)
                ->nullable()
                ->comment('Total seconds that a position had since opened_at to closed_at, to be considered fast tracked. E.g.: 60 means, only positions that were opened and closed in less than 60 seconds');

            $table->unsignedInteger('fast_trade_position_closed_age_seconds')
                ->default(3600)
                ->nullable()
                ->comment('Total seconds after a position as been closed to consider a position as fast tracked. E.g: 3600 means only take in consideration for possible fast track positions that were closed no more than 1h ago');

            $table->unsignedBigInteger('minimum_balance')
                ->nullable()
                ->comment('The minimum available balance to open a new position. If zero, then it will be not verified');

            $table->unsignedInteger('position_leverage_long')
                ->default(20)
                ->comment('The max leverage that the position LONG can use');

            $table->unsignedInteger('position_leverage_short')
                ->default(15)
                ->comment('The max leverage that the position LONG can use');

            $table->unsignedInteger('total_limit_orders')
                ->default(4)
                ->comment('Total limit orders, for the martingale calculation');

            $table->json('indicator_timeframes')->nullable()
                ->comment('Taapi timeframes considered for the trade configuration');

            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();

            $table->string('canonical')->unique();
            $table->string('name');

            $table->timestamps();
        });

        Schema::create('account_balance_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id');

            $table->decimal('total_wallet_balance', 20, 5)
                ->nullable();

            $table->decimal('total_unrealized_profit', 20, 5)
                ->nullable();

            $table->decimal('total_maintenance_margin', 20, 5)
                ->nullable();

            $table->decimal('total_margin_balance', 20, 5)
                ->nullable();

            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            $table->uuid();

            $table->foreignId('user_id')
                ->comment('The related user id');

            $table->foreignId('api_system_id')
                ->comment('The related api system id');

            $table->foreignId('trade_configuration_id')
                ->comment('The related trade configuration id');

            $table->foreignId('portfolio_quote_id')
                ->nullable()
                ->comment('The related portfolio quote, to obtain the respective balance and work with that portfolio');

            $table->foreignId('trading_quote_id')
                ->nullable()
                ->comment('The related coin quote, to open positions and be rich');

            $table->decimal('margin', 20, 8)
                ->nullable()
                ->comment('If filled, it will be used, instead of the trade configuration default margin percentage');

            $table->boolean('can_trade')
                ->default(true)
                ->comment('If true then it will be used to dispatch positions for this account');

            $table->unsignedBigInteger('last_notified_account_balance_history_id')
                ->nullable()
                ->comment('The last report id when send report data');

            $table->json('credentials')
                ->nullable()
                ->comment('Non-testing credentials');

            $table->json('credentials_testing')
                ->nullable()
                ->comment('Testing-scoped credentials');

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('forbidden_hostnames', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->nullable();
            $table->ipAddress('ip_address');

            $table->timestamps();
        });

        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();

            $table->string('relatable_type')->nullable();
            $table->unsignedBigInteger('relatable_id')->nullable();

            $table->foreignId('api_system_id');

            $table->integer('http_response_code')->nullable();
            $table->json('debug_data')->nullable();

            $table->timestamp('started_at')
                ->nullable();

            $table->timestamp('completed_at')
                ->nullable();

            $table->unsignedInteger('duration')
                ->default(0);

            $table->string('path')->nullable();
            $table->longText('payload')->nullable();
            $table->string('http_method')->nullable();
            $table->longText('http_headers_sent')->nullable();
            $table->longText('response')->nullable();
            $table->longText('http_headers_returned')->nullable();
            $table->string('hostname')->nullable();
            $table->longText('error_message')->nullable();

            $table->timestamps();
        });

        Schema::create('api_systems', function (Blueprint $table) {
            $table->id();

            $table->boolean('is_exchange')->default(true);

            $table->string('name');

            $table->unsignedInteger('recvwindow_margin')
                ->default(10000)
                ->comment('The miliseconds margin so we dont get errors due to server time vs exchange time desynchronizations');

            $table->string('canonical')->unique();
            $table->string('taapi_canonical')->nullable();

            $table->timestamps();
        });

        Schema::create('application_logs', function (Blueprint $table) {
            $table->id();

            $table->string('block_uuid')->nullable();
            $table->morphs('loggable');
            $table->text('event');

            $table->timestamps();
        });

        Schema::create('symbols', function (Blueprint $table) {
            $table->id();
            $table->string('token')
                ->nullable()
                ->unique();

            $table->string('name')
                ->nullable();

            $table->text('description')
                ->nullable();

            $table->string('site_url')
                ->nullable();

            $table->string('image_url')
                ->nullable();

            $table->unsignedInteger('cmc_id');

            $table->timestamps();
        });

        Schema::create('base_asset_mappers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_system_id');
            $table->string('symbol_token');
            $table->string('exchange_token');
            $table->timestamps();
        });

        Schema::create('exchange_symbols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id');
            $table->foreignId('quote_id');
            $table->unsignedInteger('api_system_id');

            $table->boolean('is_active')
                ->default(false)
                ->comment('If this exchange symbol will be available for trading');

            $table->string('direction')
                ->nullable()
                ->comment('The exchange symbol open position direction (LONG, SHORT)');

            $table->decimal('percentage_gap_long', 5, 2)
                // ->default(7.5)
                ->default(0.15)
                ->comment('Order limit laddered percentage gaps used when the position is a LONG');

            $table->decimal('percentage_gap_short', 5, 2)
                // ->default(8.5)
                ->default(0.15)
                ->comment('Order limit laddered percentage gaps used when the position is a SHORT');

            $table->unsignedInteger('price_precision');
            $table->unsignedInteger('quantity_precision');
            $table->decimal('min_notional', 20, 8)->nullable()
                ->comment('The minimum position size that can be opened (quantity x price at the moment of the position opening)');

            $table->decimal('tick_size', 20, 8);
            $table->longText('symbol_information')->nullable();
            $table->longText('leverage_brackets')->nullable();
            $table->decimal('mark_price', 20, 8)->nullable();
            $table->text('indicators_values')->nullable();
            $table->string('indicators_timeframe')->nullable();
            $table->timestamp('indicators_synced_at')->nullable();
            $table->timestamp('mark_price_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['symbol_id', 'api_system_id', 'quote_id']);
        });

        if (Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_email_unique');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'email_verified_at',
                'email',
                'password',
                'created_at',
                'updated_at',
            ]);

            $table->string('name')->nullable()->change();
            $table->timestamp('previous_logged_in_at')->nullable()->after('remember_token');
            $table->timestamp('last_logged_in_at')->nullable()->after('previous_logged_in_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->unique()->after('name');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('password')->nullable()->after('email_verified_at');

            $table->boolean('is_active')
                ->default(false);

            $table->boolean('is_admin')
                ->default(false);

            $table->text('pushover_key')->nullable()->after('password');
            $table->boolean('is_trader')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id');

            $table->uuid();

            $table->string('type')
                ->comment('PROFIT, MARKET, LIMIT, CANCEL-MARKET');

            $table->string('reference_status')
                ->nullable()
                ->comment('The referenced order status, only progresses manually');

            $table->string('status')
                ->default('NEW')
                ->comment('The order status (filled, cancelled, new, etc), progresses via apiSync');

            $table->string('side')
                ->comment('BUY or SELL - To open a short, or a long');

            $table->string('exchange_order_id')
                ->nullable()
                ->comment('The exchange system order id');

            $table->decimal('reference_quantity', 20, 8)
                ->nullable()
                ->comment('The order refered initial or filled quantity, which should be the right value');

            $table->decimal('quantity', 20, 8)
                ->nullable()
                ->comment('The order initial or filled quantity, depending on the order status');

            $table->decimal('reference_price', 20, 8)
                ->nullable()
                ->comment('The order refered initial or filled price, which should be the right value');

            $table->decimal('price', 20, 8)
                ->nullable()
                ->comment('The order initial or average price, depending on the status');

            $table->timestamp('opened_at')->nullable();

            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id');
            $table->foreignId('exchange_symbol_id')
                ->nullable();

            $table->string('status')->default('new')
                ->comment('The position status: new (never synced/syncing), active (totally synced), closed (synced, but no longer active), cancelled (there was an error or was compulsively cancelled)');

            $table->string('direction')
                ->nullable()
                ->comment('The position direction: LONG, or SHORT');

            $table->uuid();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->boolean('was_fast_traded')
                ->default(false)
                ->comment('Indicates if this position was considered fast traded (less than the fast traded duration)');

            $table->unsignedInteger('total_limit_orders')
                ->nullable()
                ->comment('Total position limit orders');

            $table->decimal('opening_price', 20, 8)
                ->nullable()
                ->comment('The current exchange symbol mark price when the position was opened');

            $table->decimal('margin', 20, 8)->nullable()
                ->comment('The position margin (meaning the portfolio amount without leverage)');

            $table->decimal('closing_price', 20, 8)
                ->nullable()
                ->comment('The last profit price');

            $table->json('indicators_values')
                ->nullable()
                ->comment('The indicator result at the moment that the position was created');

            $table->string('indicators_timeframe')
                ->nullable()
                ->comment('The indicator timeframe when the position was created');

            $table->unsignedTinyInteger('leverage')->nullable();

            $table->decimal('profit_percentage', 6, 3)->nullable()
                ->comment('The profit percentage obtained from the trade configuration');

            $table->text('error_message')->nullable();

            $table->timestamps();
        });

        Schema::create('order_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id');
            $table->string('orderId')->nullable();
            $table->string('symbol')->nullable();
            $table->string('status')->nullable();
            $table->string('clientOrderId')->nullable();
            $table->string('price')->nullable();
            $table->string('avgPrice')->nullable();
            $table->string('origQty')->nullable();
            $table->string('executedQty')->nullable();
            $table->string('cumQuote')->nullable();
            $table->string('timeInForce')->nullable();
            $table->string('type')->nullable();
            $table->string('reduceOnly')->nullable();
            $table->string('closePosition')->nullable();
            $table->string('side')->nullable();
            $table->string('positionSide')->nullable();
            $table->string('stopPrice')->nullable();
            $table->string('workingType')->nullable();
            $table->string('priceProtect')->nullable();
            $table->string('origType')->nullable();
            $table->string('priceMatch')->nullable();
            $table->string('selfTradePreventionMode')->nullable();
            $table->string('goodTillDate')->nullable();
            $table->string('time')->nullable();
            $table->string('updateTime')->nullable();
            $table->timestamps();
        });

        Artisan::call('db:seed', [
            '--class' => SchemaSeeder1::class,
        ]);
    }
};
