<?php

namespace App\Console\Commands;

use App\Services\Business\SlotService;
use Illuminate\Console\Command;

class GenerateSlots extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'slots:generate
                            {--days= : Number of days to generate slots for (default: 30)}
                            {--doctor= : Generate slots for specific doctor ID}';

    /**
     * The console command description.
     */
    protected $description = 'Generate appointment slots for doctors';

    /**
     * Execute the console command.
     */
    public function handle(SlotService $slotService): int
    {
        $days = $this->option('days') ?? config('hospital.slots.pre_generate_days', 30);
        $doctorId = $this->option('doctor');

        $this->info("🔧 Generating slots for next {$days} days...");
        $this->newLine();

        if ($doctorId) {
            // Generate for specific doctor
            $startDate = now();
            $endDate = now()->addDays($days);

            $count = $slotService->generateSlotsForDateRange(
                $doctorId,
                $startDate,
                $endDate
            );

            $this->info("✅ Generated {$count} slots for doctor #{$doctorId}");
        } else {
            // Generate for all active doctors
            $totalGenerated = $slotService->generateSlotsForAllDoctors($days);
            $this->info("✅ Generated {$totalGenerated} total slots for all active doctors");
        }

        $this->newLine();
        $this->info('Slot generation completed successfully!');

        return Command::SUCCESS;
    }
}
