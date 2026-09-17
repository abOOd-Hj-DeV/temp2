<?php

// database/migrations/xxxx_xx_xx_xxxxxx_create_therapists_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('therapists', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->string('full_name');
            $table->string('specialty');
            $table->string('country');
            $table->json('languages')->nullable(); // ['ar', 'en', 'fr']
            $table->string('license_file_path')->nullable();
            $table->text('bio')->nullable();
            $table->float('rating')->default(0);
            $table->json('availability')->nullable(); // {"monday": ["09:00-12:00"], ...}
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->integer('clients_count')->default(0);
            $table->integer('clients_limit')->default(20);

            $table->timestamps();

            // Indexes
            $table->index('approval_status');
            $table->index('country');
            $table->index('specialty');
        });
    }

    public function down()
    {
        Schema::dropIfExists('therapists');
    }
};
