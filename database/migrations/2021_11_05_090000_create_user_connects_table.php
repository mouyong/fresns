<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserConnectsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_connects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('connect_id')->index('connect_id');
            $table->string('connect_token', 128)->unique('connect_token');
            $table->string('connect_name', 64)->nullable();
            $table->string('connect_nickname', 64);
            $table->string('connect_avatar')->nullable();
            $table->string('plugin_unikey', 64);
            $table->unsignedTinyInteger('is_enable')->default('1');
            $table->json('more_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_connects');
    }
}
