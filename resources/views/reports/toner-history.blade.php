<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Отчет по картриджам</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
            margin: 0;
            padding: 24px;
        }

        h1 {
            font-size: 18px;
            margin: 0 0 6px;
        }

        .meta {
            margin-bottom: 18px;
            color: #4b5563;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #9ca3af;
            padding: 8px 6px;
            vertical-align: top;
            text-align: left;
        }

        th {
            background: #f3f4f6;
            font-weight: 700;
        }

        .signature-cell {
            min-height: 42px;
            width: 120px;
        }
    </style>
</head>
<body>
    <h1>Отчет по картриджам</h1>
    <div class="meta">
        Сформирован: {{ $generatedAt->format('d.m.Y H:i') }}<br>
        Количество позиций: {{ $supplies->count() }}
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Слот</th>
                <th>Принтер</th>
                <th>Цвет</th>
                <th>Комментарий</th>
                <th>% тонера</th>
                <th>Подпись получателя</th>
                <th>Подпись владельца</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($supplies as $supply)
                <tr>
                    <td>{{ $supply->id }}</td>
                    <td>{{ $supply->display_name }}</td>
                    <td>{{ $supply->display_slot }}</td>
                    <td>{{ $supply->printer?->display_name ?? '—' }}</td>
                    <td>{{ $supply->color_label }}</td>
                    <td>{{ $supply->comment_display }}</td>
                    <td>{{ $supply->percentage_display }}</td>
                    <td class="signature-cell"></td>
                    <td class="signature-cell"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
