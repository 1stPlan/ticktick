@extends('layouts.app')

@section('title', 'AI秘書 - チャット')

@section('content')
<div class="flex flex-col h-screen max-w-2xl mx-auto">
    {{-- ヘッダー --}}
    <header class="flex items-center justify-between px-4 py-3 bg-white border-b border-slate-200 shrink-0">
        <h1 class="text-lg font-semibold text-slate-800">AI秘書</h1>
        @if ($tickTickConnected)
            <form method="POST" action="{{ route('ticktick.disconnect') }}" class="inline">
                @csrf
                <span class="text-xs px-2 py-1 rounded-full bg-emerald-100 text-emerald-700 mr-2">TickTick 連携済み</span>
                <button type="submit" class="text-xs text-slate-500 hover:text-slate-700">連携解除</button>
            </form>
        @else
            <a href="{{ $connectUrl }}" class="text-sm text-blue-600 hover:text-blue-800 hover:underline">TickTick と連携</a>
        @endif
    </header>

    @if (session('success'))
        <div class="mx-4 mt-2 px-4 py-2 bg-emerald-100 text-emerald-800 rounded-lg text-sm">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mx-4 mt-2 px-4 py-2 bg-red-100 text-red-800 rounded-lg text-sm">{{ session('error') }}</div>
    @endif

    {{-- メッセージ一覧 --}}
    <main id="messages" class="flex-1 overflow-y-auto p-4 space-y-4">
        <div class="text-center text-slate-500 text-sm py-8">
            メッセージを入力して送信してください。予定の確認やタスクの追加ができます。
        </div>
    </main>

    {{-- 入力フォーム --}}
    <form id="chat-form" class="p-4 bg-white border-t border-slate-200 shrink-0">
        <div class="flex gap-2">
            <textarea
                id="message-input"
                name="message"
                rows="1"
                maxlength="2000"
                placeholder="メッセージを入力..."
                class="flex-1 resize-none rounded-lg border-slate-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 px-4 py-3 text-sm"
            ></textarea>
            <button
                type="submit"
                id="send-btn"
                class="shrink-0 px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition"
            >
                送信
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('chat-form');
    const input = document.getElementById('message-input');
    const messagesEl = document.getElementById('messages');
    const sendBtn = document.getElementById('send-btn');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const text = input.value.trim();
        if (!text) return;

        // ユーザーメッセージを表示
        appendMessage('user', text);
        input.value = '';
        sendBtn.disabled = true;

        // ローディング表示
        const loadingId = 'loading-' + Date.now();
        appendMessage('assistant', '...', loadingId);

        try {
            const res = await fetch('{{ route("chat.store") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ message: text }),
            });

            const data = await res.json();

            // ローディングを削除
            const loading = document.getElementById(loadingId);
            if (loading) loading.remove();

            if (res.ok) {
                appendMessage('assistant', data.message);
            } else {
                appendMessage('assistant', data.error || 'エラーが発生しました', null, true);
            }
        } catch (err) {
            const loading = document.getElementById(loadingId);
            if (loading) loading.remove();
            appendMessage('assistant', '通信エラーです。もう一度お試しください。', null, true);
        } finally {
            sendBtn.disabled = false;
        }
    });

    function appendMessage(role, content, id = null, isError = false) {
        const hint = document.querySelector('.text-slate-500');
        if (hint) hint.remove();

        const div = document.createElement('div');
        div.className = 'flex ' + (role === 'user' ? 'justify-end' : 'justify-start');
        if (id) div.id = id;

        const bubble = document.createElement('div');
        bubble.className = 'max-w-[85%] rounded-2xl px-4 py-2.5 ' +
            (role === 'user'
                ? 'bg-blue-600 text-white rounded-br-md'
                : (isError ? 'bg-red-100 text-red-800' : 'bg-white border border-slate-200 text-slate-800') + ' rounded-bl-md');

        bubble.textContent = content;
        bubble.style.whiteSpace = 'pre-wrap';
        bubble.style.wordBreak = 'break-word';
        div.appendChild(bubble);
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }
});
</script>
@endsection
