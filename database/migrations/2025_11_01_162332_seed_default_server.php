<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Http;
use Martingalian\Core\Models\Server;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fetch public IP address
        $ipAddress = null;
        try {
            $response = Http::timeout(5)->get('https://api.ipify.org?format=text');
            if ($response->successful()) {
                $ipAddress = trim($response->body());
            }
        } catch (\Throwable $e) {
            // Fallback: try alternative service
            try {
                $response = Http::timeout(5)->get('https://icanhazip.com');
                if ($response->successful()) {
                    $ipAddress = trim($response->body());
                }
            } catch (\Throwable $e2) {
                // If both fail, leave as null
            }
        }

        Server::create([
            'hostname' => 'DELLXPS15',
            'ip_address' => $ipAddress,
            'type' => 'ingestion',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Server::where('hostname', 'DELLXPS15')->delete();
    }
};
