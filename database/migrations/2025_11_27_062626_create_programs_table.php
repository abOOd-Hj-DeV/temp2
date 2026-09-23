<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_programs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->text('description');
            $table->boolean('is_core')->default(false);

            $table->timestamps();

            // Indexes
            $table->index('is_core');
        });
    }

    public function down()
    {
        Schema::dropIfExists('programs');
    }
};
