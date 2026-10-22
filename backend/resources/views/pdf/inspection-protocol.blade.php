@extends('pdf.layout')

@section('title', 'Inventarizatsiya bayonnomasi '.$inspection->number)

@section('content')
    @if ($inspection->is_demo)
        <div class="demo">DEMO MA'LUMOT — haqiqiy hujjat emas</div>
    @endif

    <h1>Aloqa kabel liniyalarini inventarizatsiya qilish bayonnomasi</h1>
    <div class="subtitle">№ {{ $inspection->number }} — {{ $inspection->title }}</div>

    <table class="meta">
        <tr><td class="label">MGQB / tashkilot:</td><td>{{ $inspection->organization?->name }}</td></tr>
        <tr><td class="label">Rejalashtirilgan muddat:</td><td>{{ $inspection->planned_start?->format('d.m.Y') }} — {{ $inspection->planned_end?->format('d.m.Y') }}</td></tr>
        <tr><td class="label">Holati:</td><td>{{ $inspection->status->label() }}</td></tr>
        @if ($inspection->decided_at)
            <tr><td class="label">Qaror:</td><td>{{ $inspection->decider?->name }}, {{ $inspection->decided_at->format('d.m.Y H:i') }} — {{ $inspection->decision_comment }}</td></tr>
        @endif
    </table>

    <table>
        <thead>
        <tr>
            <th>Uchastka</th><th>Liniya</th><th>Ko'rik sanasi</th>
            <th class="num">Hisobda, m</th><th class="num">Aniqlangan, m</th><th class="num">Farq, m</th>
            <th>Farq sababi</th><th>Texnik holat</th><th>Foydalanish</th><th>Xavf</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($inspection->items as $item)
            <tr>
                <td>{{ $item->segment->code }}</td>
                <td>{{ $item->segment->cableLine?->name }}</td>
                <td>{{ $item->inspected_on?->format('d.m.Y') }}</td>
                <td class="num">{{ $item->recorded_length_m }}</td>
                <td class="num">{{ $item->measured_length_m }}</td>
                <td class="num {{ $item->exceedsLengthTolerance() ? 'warn' : '' }}">{{ $item->length_difference_m }}</td>
                <td>{{ $item->difference_reason }}</td>
                <td>{{ $item->technical_condition?->label() }}</td>
                <td>{{ $item->usage_status?->label() }}@if ($item->usage_evidence) — {{ $item->usage_evidence }}@endif</td>
                <td>{{ $item->risk_level?->label() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="signatures">
        @foreach ($inspection->members as $member)
            <tr>
                <td style="width: 35%">{{ $member->role->label() }}</td>
                <td style="width: 35%">{{ $member->full_name }}@if ($member->position), {{ $member->position }}@endif</td>
                <td>____________________</td>
            </tr>
        @endforeach
    </table>
@endsection
