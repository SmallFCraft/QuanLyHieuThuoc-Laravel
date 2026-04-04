<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_images')) {
            return;
        }

        Schema::table('chatbot_images', function (Blueprint $table) {
            if (! Schema::hasColumn('chatbot_images', 'filename')) {
                $table->string('filename')->nullable()->after('session_id');
            }

            if (! Schema::hasColumn('chatbot_images', 'original_name')) {
                $table->string('original_name')->nullable()->after('filename');
            }

            if (! Schema::hasColumn('chatbot_images', 'file_path')) {
                $table->string('file_path')->nullable()->after('image_path');
            }

            if (! Schema::hasColumn('chatbot_images', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable()->after('file_path');
            }

            if (! Schema::hasColumn('chatbot_images', 'mime_type')) {
                $table->string('mime_type', 120)->nullable()->after('file_size');
            }

            if (! Schema::hasColumn('chatbot_images', 'ocr_text')) {
                $table->longText('ocr_text')->nullable()->after('analysis_result');
            }

            if (! Schema::hasColumn('chatbot_images', 'recognized_products')) {
                $table->json('recognized_products')->nullable()->after('ocr_text');
            }

            if (! Schema::hasColumn('chatbot_images', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('recognized_products');
            }

            if (! Schema::hasColumn('chatbot_images', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        if (Schema::hasColumn('chatbot_images', 'file_path') && Schema::hasColumn('chatbot_images', 'image_path')) {
            DB::table('chatbot_images')
                ->whereNull('file_path')
                ->select(['id', 'image_path'])
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('chatbot_images')
                            ->where('id', $row->id)
                            ->update(['file_path' => $row->image_path]);
                    }
                });
        }

        if (Schema::hasColumn('chatbot_images', 'ocr_text') && Schema::hasColumn('chatbot_images', 'analysis_result')) {
            DB::table('chatbot_images')
                ->whereNull('ocr_text')
                ->whereNotNull('analysis_result')
                ->select(['id', 'analysis_result'])
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('chatbot_images')
                            ->where('id', $row->id)
                            ->update(['ocr_text' => $row->analysis_result]);
                    }
                });
        }

        if (Schema::hasColumn('chatbot_images', 'filename') && Schema::hasColumn('chatbot_images', 'file_path')) {
            DB::table('chatbot_images')
                ->whereNull('filename')
                ->whereNotNull('file_path')
                ->select(['id', 'file_path'])
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('chatbot_images')
                            ->where('id', $row->id)
                            ->update(['filename' => basename($row->file_path)]);
                    }
                });
        }

        if (Schema::hasColumn('chatbot_images', 'original_name') && Schema::hasColumn('chatbot_images', 'filename')) {
            DB::table('chatbot_images')
                ->whereNull('original_name')
                ->whereNotNull('filename')
                ->select(['id', 'filename'])
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('chatbot_images')
                            ->where('id', $row->id)
                            ->update(['original_name' => $row->filename]);
                    }
                });
        }

        if (Schema::hasColumn('chatbot_images', 'session_id')) {
            DB::table('chatbot_images')
                ->whereNull('session_id')
                ->select(['id'])
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('chatbot_images')
                            ->where('id', $row->id)
                            ->update(['session_id' => (string) Str::uuid()]);
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('chatbot_images')) {
            return;
        }

        Schema::table('chatbot_images', function (Blueprint $table) {
            if (Schema::hasColumn('chatbot_images', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            $columnsToDrop = array_filter([
                Schema::hasColumn('chatbot_images', 'processed_at') ? 'processed_at' : null,
                Schema::hasColumn('chatbot_images', 'recognized_products') ? 'recognized_products' : null,
                Schema::hasColumn('chatbot_images', 'ocr_text') ? 'ocr_text' : null,
                Schema::hasColumn('chatbot_images', 'mime_type') ? 'mime_type' : null,
                Schema::hasColumn('chatbot_images', 'file_size') ? 'file_size' : null,
                Schema::hasColumn('chatbot_images', 'file_path') ? 'file_path' : null,
                Schema::hasColumn('chatbot_images', 'original_name') ? 'original_name' : null,
                Schema::hasColumn('chatbot_images', 'filename') ? 'filename' : null,
            ]);

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
