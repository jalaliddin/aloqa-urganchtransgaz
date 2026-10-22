{{-- Shared PDF layout. Edit freely: templates are plain Blade. --}}
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 10px; color: #1a1b1e; margin: 0; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 4px; text-transform: uppercase; }
        .subtitle { text-align: center; margin-bottom: 14px; color: #495057; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #868e96; padding: 4px 5px; vertical-align: top; }
        th { background: #f1f3f5; text-align: left; }
        .meta td { border: none; padding: 2px 0; }
        .meta td.label { width: 32%; color: #495057; }
        .num { text-align: right; white-space: nowrap; }
        .warn { color: #c92a2a; }
        .demo { border: 1px solid #e8590c; color: #e8590c; padding: 4px; text-align: center; margin-bottom: 10px; }
        .signatures td { border: none; padding-top: 18px; }
        .footer { margin-top: 16px; font-size: 8px; color: #868e96; }
    </style>
</head>
<body>
@yield('content')
<div class="footer">Hujjat {{ now()->format('d.m.Y H:i') }} da "Aloqa kabel reyestri" tizimidan shakllantirildi. Imzolanmagan nusxa yuridik kuchga ega emas.</div>
</body>
</html>
