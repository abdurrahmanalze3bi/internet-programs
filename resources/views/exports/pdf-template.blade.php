{{-- resources/views/exports/pdf-template.blade.php --}}
    <!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #333;
            margin: 0;
        }
        .header p {
            color: #666;
            margin: 5px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4CAF50;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
<div class="header">
    <h1>{{ $title }}</h1>
    <p>Government Complaints System</p>
    <p>Generated: {{ $generated_at }}</p>
</div>

<table>
    <thead>
    <tr>
        @if(!empty($data))
            @foreach(array_keys($data[0]) as $header)
                <th>{{ ucfirst(str_replace('_', ' ', $header)) }}</th>
            @endforeach
        @endif
    </tr>
    </thead>
    <tbody>
    @foreach($data as $row)
        <tr>
            @foreach($row as $cell)
                <td>{{ is_array($cell) ? json_encode($cell) : $cell }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
