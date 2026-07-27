<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->string('presentation_type')->default('standard')->after('type')->index();
            $table->json('guide_metadata')->nullable()->after('onefivefour_metadata');
        });

        DB::table('articles')
            ->where('slug', 'bitola-kulturen-vodic-20-26-juli-2026')
            ->update([
                'presentation_type' => 'event_guide',
                'guide_metadata' => json_encode([
                    'meta' => [
                        'period_label' => '20 до 26 јули',
                        'city_label' => 'Битола',
                        'last_verified_at' => '2026-07-20',
                    ],
                    'events' => [
                        [
                            'id' => 'bit-fest-prodolzuva',
                            'title' => 'Бит Фест продолжува',
                            'start_date' => '2026-07-20',
                            'end_date' => '2026-07-26',
                            'location' => 'Битола',
                            'short_description' => 'Музика, театар, изложби и програма за различни генерации. Проверете ги последните соопштенија пред тргнување.',
                            'source_url' => 'https://kicbitola.mk/category/%D0%B8%D0%B4%D0%BD%D0%B8-%D0%BD%D0%B0%D1%81%D1%82%D0%B0%D0%BD%D0%B8/',
                            'source_label' => 'КИЦ Битола',
                            'display_order' => 10,
                        ],
                        [
                            'id' => 'jubilejna-vecer-herakleja',
                            'title' => 'Јубилејна вечер во Хераклеја',
                            'start_date' => '2026-07-22',
                            'time' => '20:30',
                            'location' => 'Хераклеја',
                            'price_label' => '600 денари',
                            'short_description' => 'Народниот театар Битола го најавува настанот „30 естрадни години“ во античкиот локалитет.',
                            'source_url' => 'https://narodenteatarbitola.com/en/events/jubilej-30g/',
                            'source_label' => 'Народен театар Битола',
                            'display_order' => 20,
                        ],
                        [
                            'id' => 'ilindenski-denovi',
                            'title' => '„Илинденски денови“',
                            'start_date' => '2026-07-26',
                            'end_date' => '2026-08-01',
                            'location' => 'Битола',
                            'short_description' => 'CIOFF ја наведува главната меѓународна програма од 26 јули до 1 август. Дневниот распоред проверете го кај организаторот.',
                            'source_url' => 'https://www.cioff.org/festival/north-macedonia/3601',
                            'source_label' => 'CIOFF',
                            'display_order' => 30,
                        ],
                    ],
                    'practical_notes' => [
                        'Проверете дали има измени во програмата пред тргнување.',
                        'Проверете дали е потребна резервација или дали влезот е слободен.',
                        'За настани на отворено пристигнете порано и проверете ги временските услови.',
                    ],
                    'sources' => [
                        [
                            'label' => 'КИЦ Битола',
                            'url' => 'https://kicbitola.mk/category/%D0%B8%D0%B4%D0%BD%D0%B8-%D0%BD%D0%B0%D1%81%D1%82%D0%B0%D0%BD%D0%B8/',
                        ],
                        [
                            'label' => 'Народен театар Битола',
                            'url' => 'https://narodenteatarbitola.com/en/events/jubilej-30g/',
                        ],
                        [
                            'label' => 'CIOFF',
                            'url' => 'https://www.cioff.org/festival/north-macedonia/3601',
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
    }

    public function down(): void
    {
        DB::table('articles')
            ->where('slug', 'bitola-kulturen-vodic-20-26-juli-2026')
            ->update([
                'presentation_type' => 'standard',
                'guide_metadata' => null,
            ]);

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn(['presentation_type', 'guide_metadata']);
        });
    }
};
