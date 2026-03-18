<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\TickTickController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    if (app()->environment('production')) {
        return response(
            '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI秘書</title></head><body style="font-family:sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f5f5"><div style="background:#fff;padding:2rem;border-radius:12px;text-align:center;max-width:360px"><h1 style="font-size:1.25rem;color:#333;margin:0 0 1rem">AI秘書</h1><p style="color:#666;font-size:0.9rem;line-height:1.6;margin:0">このアプリは LINE でご利用ください。<br>友だち追加してメッセージを送ると、予定の確認やタスクの追加ができます。</p></div></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    return redirect()->route('chat.index');
})->name('home');

if (! app()->environment('production')) {
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat', [ChatController::class, 'store'])->name('chat.store');
}

Route::prefix('ticktick')->group(function () {
    Route::get('connect', [TickTickController::class, 'redirect'])->name('ticktick.connect');
    Route::get('callback', [TickTickController::class, 'callback'])->name('ticktick.callback');
    Route::get('line-success', function () {
        return response(
            '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>連携完了</title></head><body style="font-family:sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f5f5"><div style="background:#fff;padding:2rem;border-radius:12px;text-align:center;max-width:360px"><h1 style="font-size:1.5rem;color:#06c755;margin:0 0 1rem">✓ TickTick と連携しました</h1><p style="color:#333;line-height:1.6;margin:0">LINE に戻って、メッセージを送ってみてください。<br>予定の確認やタスクの追加ができます。</p></div></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    })->name('ticktick.line-success');
    Route::post('disconnect', [TickTickController::class, 'disconnect'])->name('ticktick.disconnect');
});
