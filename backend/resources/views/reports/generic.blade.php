<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 30px 32px 46px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1a1a1a; }
    .header { border-bottom: 2px solid #1F3A5F; padding-bottom: 10px; margin-bottom: 16px; }
    .company { font-size: 16px; font-weight: bold; color: #1F3A5F; margin: 0 0 3px; }
    .report-title { font-size: 13px; font-weight: bold; margin: 0 0 2px; }
    .sub { color: #555; font-size: 10.5px; margin-bottom: 3px; }
    .meta { color: #777; font-size: 9.5px; }
    .section-title { font-size: 11.5px; font-weight: bold; color: #1F3A5F; background: #DCE6F1; padding: 5px 8px; margin: 16px 0 0; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    thead th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .02em; color: #fff; background: #1F3A5F; padding: 6px 7px; border: 1px solid #1F3A5F; }
    tbody td { padding: 5px 7px; border: 1px solid #E3E7ED; }
    tbody tr:nth-child(even) td { background: #F8FAFC; }
    .amount { text-align: right; font-family: DejaVu Sans Mono, monospace; }
    .center { text-align: center; }
    .empty td { text-align: center; color: #999; font-style: italic; padding: 12px; }
    .total-row td { border-top: 2px solid #1F3A5F; background: #F2F4F7 !important; font-weight: bold; }
    .footer { position: fixed; bottom: -34px; left: 0; right: 0; text-align: center; font-size: 8.5px; color: #999; border-top: 1px solid #E3E7ED; padding-top: 5px; }
    .footer:after { content: "Page " counter(page) " of " counter(pages); }
</style>
</head>
<body>
    <div class="footer"></div>
    <div class="header">
        <div class="company">{{ $companyName }}</div>
        <div class="report-title">{{ $reportTitle }}</div>
        @isset($subtitle)<div class="sub">{{ $subtitle }}</div>@endisset
        <div class="meta">{{ $meta ?? '' }}{{ $meta ? ' • ' : '' }}Generated: {{ $generatedAt->format('Y-m-d H:i') }}</div>
    </div>

    @foreach ($sections as $section)
        @if (!empty($section['title']))
            <div class="section-title">{{ $section['title'] }}</div>
        @endif
        <table>
            <thead>
                <tr>
                    @foreach ($section['columns'] as $col)
                        <th class="{{ ($col['align'] ?? 'left') === 'right' ? 'amount' : (($col['align'] ?? 'left') === 'center' ? 'center' : '') }}" @if(!empty($col['width'])) style="width:{{ $col['width'] }}" @endif>{{ $col['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($section['rows'] as $row)
                    <tr>
                        @foreach ($section['columns'] as $col)
                            @php
                                $val = $row[$col['key']] ?? null;
                                $fmt = $col['format'] ?? 'text';
                                $align = ($col['align'] ?? 'left') === 'right' ? 'amount' : (($col['align'] ?? 'left') === 'center' ? 'center' : '');
                            @endphp
                            <td class="{{ $align }}">
                                @if ($val === null || $val === '')
                                    —
                                @elseif ($fmt === 'money')
                                    {{ number_format((float) $val, 2) }}
                                @elseif ($fmt === 'number')
                                    {{ number_format((float) $val) }}
                                @elseif ($fmt === 'percent')
                                    {{ number_format((float) $val, 2) }}%
                                @else
                                    {{ $val }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr class="empty"><td colspan="{{ count($section['columns']) }}">{{ $section['emptyText'] ?? 'No data for this range' }}</td></tr>
                @endforelse
                @if (!empty($section['totalRow']))
                    <tr class="total-row">
                        @foreach ($section['columns'] as $col)
                            @php
                                $val = $section['totalRow'][$col['key']] ?? null;
                                $fmt = $col['format'] ?? 'text';
                                $align = ($col['align'] ?? 'left') === 'right' ? 'amount' : (($col['align'] ?? 'left') === 'center' ? 'center' : '');
                            @endphp
                            <td class="{{ $align }}">
                                @if ($val === null || $val === '')

                                @elseif ($fmt === 'money')
                                    {{ number_format((float) $val, 2) }}
                                @elseif ($fmt === 'number')
                                    {{ number_format((float) $val) }}
                                @elseif ($fmt === 'percent')
                                    {{ number_format((float) $val, 2) }}%
                                @else
                                    {{ $val }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @endforeach
</body>
</html>
