<?php
use App\Http\Controllers\DocumentUploadController;
use Illuminate\Support\Facades\Route;
Route::post('/documents', [DocumentUploadController::class, 'store'])->middleware('throttle:10,1');
Route::delete('/documents', [DocumentUploadController::class, 'destroy'])->middleware('throttle:5,1');
