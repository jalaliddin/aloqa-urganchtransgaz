@extends('pdf.layout')

@section('title', 'Demontaj dalolatnomasi '.$order->number)

@section('content')
    @if ($order->is_demo)
        <div class="demo">DEMO MA'LUMOT — haqiqiy hujjat emas</div>
    @endif

    <h1>Aloqa kabelini demontaj qilish dalolatnomasi</h1>
    <div class="subtitle">Topshiriq № {{ $order->number }} (loyiha — imzolash uchun)</div>

    <table class="meta">
        <tr><td class="label">MGQB:</td><td>{{ $order->organization?->name }}</td></tr>
        <tr><td class="label">Kabel liniyasi:</td><td>{{ $order->cableLine?->registry_number }} — {{ $order->cableLine?->name }}</td></tr>
        <tr><td class="label">Ish reja / buyruq:</td><td>{{ $order->work_plan_number ?? '—' }} / {{ $order->order_number ?? '—' }} {{ $order->order_date?->format('d.m.Y') }}</td></tr>
        <tr><td class="label">Brigada / ish rahbari:</td><td>{{ $order->brigade_name ?? '—' }} / {{ $order->workSupervisor?->name ?? $order->work_supervisor_name ?? '—' }}</td></tr>
        <tr><td class="label">Muddat (reja):</td><td>{{ $order->planned_start?->format('d.m.Y') }} — {{ $order->planned_end?->format('d.m.Y') }}</td></tr>
        <tr><td class="label">Muddat (fakt):</td><td>{{ $order->actual_start?->format('d.m.Y') }} — {{ $order->actual_end?->format('d.m.Y') }}</td></tr>
        <tr><td class="label">Holati:</td><td>{{ $order->status->label() }}</td></tr>
    </table>

    <table>
        <thead><tr><th>Uchastka</th><th>Nomi</th><th class="num">Rejalashtirilgan, m</th><th>Xavf</th></tr></thead>
        <tbody>
        @foreach ($order->segments as $segment)
            <tr>
                <td>{{ $segment->code }}</td>
                <td>{{ $segment->name }}</td>
                <td class="num">{{ $segment->pivot->planned_length_m }}</td>
                <td>{{ $segment->risk_level->label() }}</td>
            </tr>
        @endforeach
        <tr><th colspan="2">Jami reja / amalda chiqarilgan</th><th class="num">{{ $order->planned_length_m }} / {{ $order->actual_length_m }}</th><th></th></tr>
        </tbody>
    </table>

    <table>
        <thead><tr><th>Kelishuv / ko'rik</th><th>Qaror</th><th>Raqam, sana</th><th>Qayd etgan</th></tr></thead>
        <tbody>
        @forelse ($order->approvals->whereNull('revoked_at') as $approval)
            <tr>
                <td>{{ $approval->type->label() }}</td>
                <td>{{ $approval->decision->label() }}</td>
                <td>{{ $approval->reference_number }} {{ $approval->reference_date?->format('d.m.Y') }}</td>
                <td>{{ $approval->decider?->name }}, {{ $approval->decided_at?->format('d.m.Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="4">Kelishuvlar qayd etilmagan.</td></tr>
        @endforelse
        </tbody>
    </table>

    <p>Eslatma: dastur xavfsizlik ruxsatini bermaydi — yuqoridagi kelishuvlar vakolatli shaxslar tomonidan qayd etilgan.</p>

    <table class="signatures">
        <tr><td style="width:40%">Ish rahbari</td><td>____________________</td></tr>
        <tr><td>MGQB mas'uli</td><td>____________________</td></tr>
        <tr><td>Ekspluatatsiya / sanoat xavfsizligi</td><td>____________________</td></tr>
    </table>
@endsection
