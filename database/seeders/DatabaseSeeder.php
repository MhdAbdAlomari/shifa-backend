<?php

namespace Database\Seeders;

use App\Models\OperatingRoom;
use App\Models\Patient;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $faker = \Faker\Factory::create();

        // --- Users --------------------------------------------------------
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@shifa.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $coordinators = collect([
            ['name' => 'Coordinator One',   'email' => 'coord1@shifa.test'],
            ['name' => 'Coordinator Two',   'email' => 'coord2@shifa.test'],
        ])->map(fn ($c) => User::create([
            'name' => $c['name'],
            'email' => $c['email'],
            'password' => Hash::make('password'),
            'role' => 'coordinator',
        ]));

        $specialties = ['Cardiology', 'Orthopedics', 'Neurology', 'General'];
        $surgeons = collect();
        foreach ($specialties as $i => $spec) {
            $surgeons->push(User::create([
                'name' => "Dr. " . $faker->lastName,
                'email' => 'surgeon' . ($i + 1) . '@shifa.test',
                'password' => Hash::make('password'),
                'role' => 'surgeon',
                'specialty' => $spec,
            ]));
        }

        // --- Operating Rooms ---------------------------------------------
        $rooms = collect();
        $rooms->push(OperatingRoom::create(['name' => 'OR-1', 'status' => 'free', 'supported_specialty' => 'Cardiology']));
        $rooms->push(OperatingRoom::create(['name' => 'OR-2', 'status' => 'free', 'supported_specialty' => 'Orthopedics']));
        $rooms->push(OperatingRoom::create(['name' => 'OR-3', 'status' => 'free', 'supported_specialty' => 'Neurology']));
        $rooms->push(OperatingRoom::create(['name' => 'OR-4', 'status' => 'free', 'supported_specialty' => 'General']));
        $rooms->push(OperatingRoom::create(['name' => 'OR-5', 'status' => 'free', 'supported_specialty' => null]));

        // --- Surgery Types -----------------------------------------------
        $types = collect([
            SurgeryType::create(['name' => 'Coronary Bypass',       'average_duration_min' => 240, 'required_specialty' => 'Cardiology']),
            SurgeryType::create(['name' => 'Knee Replacement',      'average_duration_min' => 120, 'required_specialty' => 'Orthopedics']),
            SurgeryType::create(['name' => 'Brain Tumor Resection', 'average_duration_min' => 300, 'required_specialty' => 'Neurology']),
            SurgeryType::create(['name' => 'Appendectomy',          'average_duration_min' => 60,  'required_specialty' => 'General']),
            SurgeryType::create(['name' => 'Hernia Repair',         'average_duration_min' => 90,  'required_specialty' => 'General']),
        ]);

        // --- Patients ----------------------------------------------------
        $patients = collect();
        for ($i = 1; $i <= 10; $i++) {
            $patients->push(Patient::create([
                'name' => $faker->name,
                'mrn' => 'MRN-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'medical_notes' => $faker->optional()->sentence(10),
            ]));
        }

        // --- Sample surgeries --------------------------------------------
        // Match surgeon -> surgery type by specialty for realism.
        $surgeonBySpec = $surgeons->keyBy('specialty');

        // 1. Scheduled — today afternoon
        Surgery::create([
            'patient_id' => $patients[0]->id,
            'surgeon_id' => $surgeonBySpec['Cardiology']->id,
            'room_id' => $rooms->firstWhere('supported_specialty', 'Cardiology')->id,
            'surgery_type_id' => $types->firstWhere('required_specialty', 'Cardiology')->id,
            'created_by' => $coordinators[0]->id,
            'priority' => 'normal',
            'scheduled_start' => Carbon::today()->setHour(14)->setMinute(0),
            'estimated_duration_min' => 240,
            'status' => 'scheduled',
        ]);

        // 2. In progress — started an hour ago
        Surgery::create([
            'patient_id' => $patients[1]->id,
            'surgeon_id' => $surgeonBySpec['Orthopedics']->id,
            'room_id' => $rooms->firstWhere('supported_specialty', 'Orthopedics')->id,
            'surgery_type_id' => $types->firstWhere('required_specialty', 'Orthopedics')->id,
            'created_by' => $coordinators[0]->id,
            'priority' => 'normal',
            'scheduled_start' => Carbon::now()->subHour(),
            'estimated_duration_min' => 120,
            'actual_start' => Carbon::now()->subHour(),
            'status' => 'in_progress',
        ]);

        // 3. Completed — yesterday
        Surgery::create([
            'patient_id' => $patients[2]->id,
            'surgeon_id' => $surgeonBySpec['General']->id,
            'room_id' => $rooms->firstWhere('supported_specialty', 'General')->id,
            'surgery_type_id' => $types->firstWhere('name', 'Appendectomy')->id,
            'created_by' => $coordinators[1]->id,
            'priority' => 'emergency',
            'scheduled_start' => Carbon::yesterday()->setHour(10),
            'estimated_duration_min' => 60,
            'actual_start' => Carbon::yesterday()->setHour(10),
            'actual_end' => Carbon::yesterday()->setHour(11),
            'status' => 'completed',
        ]);

        // 4. Scheduled — tomorrow morning
        Surgery::create([
            'patient_id' => $patients[3]->id,
            'surgeon_id' => $surgeonBySpec['Neurology']->id,
            'room_id' => $rooms->firstWhere('supported_specialty', 'Neurology')->id,
            'surgery_type_id' => $types->firstWhere('required_specialty', 'Neurology')->id,
            'created_by' => $coordinators[1]->id,
            'priority' => 'normal',
            'scheduled_start' => Carbon::tomorrow()->setHour(9),
            'estimated_duration_min' => 300,
            'status' => 'scheduled',
        ]);

        // 5. Scheduled — tomorrow afternoon (general)
        Surgery::create([
            'patient_id' => $patients[4]->id,
            'surgeon_id' => $surgeonBySpec['General']->id,
            'room_id' => $rooms->firstWhere('supported_specialty', 'General')->id,
            'surgery_type_id' => $types->firstWhere('name', 'Hernia Repair')->id,
            'created_by' => $coordinators[0]->id,
            'priority' => 'normal',
            'scheduled_start' => Carbon::tomorrow()->setHour(14),
            'estimated_duration_min' => 90,
            'status' => 'scheduled',
        ]);
    }
}
