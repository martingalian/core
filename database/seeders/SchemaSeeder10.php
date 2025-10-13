<?php

namespace Martingalian\Core\Database\Seeders;

use Martingalian\Core\Models\Position;
use Illuminate\Database\Seeder;

class SchemaSeeder10 extends Seeder
{
    public function run(): void
    {
        /**
         * Hardcoded profit prices based on previous calculations.
         * We are updating the `first_profit_price` for each position by ID.
         */
        $positionData = [
            1072 => 3.04389988, // TONUSDT
            1073 => 0.00318842, // DEGENUSDT
            1064 => 321.68888500, // AAVEUSDT
            1063 => 0.43392483, // ARKUSDT
            1060 => 0.30980976, // SUSDT
            1061 => 0.20292457, // PNUTUSDT
            989 => 566.23485550, // BCHUSDT
            983 => 0.95447097, // ONDUSDT
            974 => 0.26890577, // SANDUSDT
            973 => 0.82540880, // ALGUSDT
            782 => 124.80333000, // LTCUSDT
            733 => 887.30647000, // BNBUSDT
        ];

        // Loop through each position and update the first_profit_price
        foreach ($positionData as $id => $profitPrice) {
            // Find the position by ID
            $position = Position::find($id);

            if ($position) {
                // Use the updateSaving method to update the first_profit_price
                $position->updateSaving([
                    'first_profit_price' => $profitPrice,
                ]);
            }
        }
    }
}
