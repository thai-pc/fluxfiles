<?php

use FluxFiles\Laravel\Http\Controllers\FluxFilesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FluxFiles API Routes (proxy mode)
|--------------------------------------------------------------------------
|
| These routes proxy all FluxFiles API endpoints through Laravel,
| so there's no need to deploy FluxFiles as a separate server.
|
*/

// Language routes (public, no auth needed)
Route::get('lang', [FluxFilesController::class, 'langList'])->withoutMiddleware(['auth']);
Route::get('lang/{locale}', [FluxFilesController::class, 'langGet'])->withoutMiddleware(['auth']);

// API routes
Route::get('list', [FluxFilesController::class, 'list']);
Route::post('upload', [FluxFilesController::class, 'upload']);
Route::delete('delete', [FluxFilesController::class, 'delete']);
Route::post('rename', [FluxFilesController::class, 'rename']);
Route::post('move', [FluxFilesController::class, 'move']);
Route::post('copy', [FluxFilesController::class, 'copy']);
Route::post('mkdir', [FluxFilesController::class, 'mkdir']);
Route::post('cross-copy', [FluxFilesController::class, 'crossCopy']);
Route::post('cross-move', [FluxFilesController::class, 'crossMove']);
Route::post('crop', [FluxFilesController::class, 'crop']);
Route::post('ai-tag', [FluxFilesController::class, 'aiTag']);
Route::post('presign', [FluxFilesController::class, 'presign']);
Route::get('meta', [FluxFilesController::class, 'meta']);

// Metadata
Route::get('metadata', [FluxFilesController::class, 'getMetadata']);
Route::put('metadata', [FluxFilesController::class, 'saveMetadata']);
Route::delete('metadata', [FluxFilesController::class, 'deleteMetadata']);

// Search
Route::get('search', [FluxFilesController::class, 'search']);

// Quota
Route::get('quota', [FluxFilesController::class, 'quota']);

// Audit
Route::get('audit', [FluxFilesController::class, 'audit']);

// Chunk upload (multipart)
Route::post('chunk/init', [FluxFilesController::class, 'chunkInit']);
Route::post('chunk/presign', [FluxFilesController::class, 'chunkPresign']);
Route::post('chunk/complete', [FluxFilesController::class, 'chunkComplete']);
Route::post('chunk/abort', [FluxFilesController::class, 'chunkAbort']);
