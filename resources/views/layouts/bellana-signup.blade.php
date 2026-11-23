<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#300919">
    <title>Crie sua conta | Bellana</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--wine:#5B0D2B;--dark:#300919;--rose:#C76B7E;--pink:#E8B4B9;--cream:#F8EDE8;--gold:#D9A47B}
        *{box-sizing:border-box}
        body{margin:0;font-family:'DM Sans',sans-serif;background:linear-gradient(145deg,var(--dark),var(--wine));color:var(--dark);min-height:100vh}
        h1,h2,h3,.brand{font-family:Montserrat,sans-serif}a{text-decoration:none}
        .nav{max-width:1120px;margin:auto;display:flex;justify-content:space-between;align-items:center;padding:22px 24px}
        .brand{font-size:28px;font-weight:700;letter-spacing:-1.6px;color:#fff}.brand span{color:var(--gold)}
        .nav .login{color:#f8e5e7;font-size:14px}
        .signup{max-width:1120px;margin:auto;padding:30px 24px 80px;display:grid;grid-template-columns:1fr 1fr;gap:50px;align-items:start}
        .copy{color:#fff}.eyebrow{text-transform:uppercase;letter-spacing:2px;font-size:12px;color:#efb8bb;font-weight:800}
        .copy h1{font-size:clamp(32px,4vw,48px);line-height:1.1;letter-spacing:-1.5px;margin:16px 0}.copy h1 em{font-style:normal;color:#e9b3aa}
        .copy p{color:#f1d9dc;line-height:1.6}
        .price{font-family:Montserrat;font-size:52px;font-weight:800;letter-spacing:-2px;color:var(--gold);margin-top:18px}.price small{font-size:18px;font-weight:500;color:#f1d9dc}
        .copy ul{padding:0;list-style:none;line-height:2;color:#f8e5e7}.copy li span{color:var(--gold);margin-right:8px}
        .card{background:#fff;border-radius:24px;padding:34px;box-shadow:0 25px 55px #0004}
        .card h2{margin:6px 0 4px;font-size:24px}.card .sub{color:#745c67;margin:0 0 20px}
        .card .tag{text-transform:uppercase;letter-spacing:2px;font-size:11px;color:#9c526a;font-weight:800}
        .field{margin-bottom:14px}.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        label{display:block;font-weight:700;font-size:13px;margin-bottom:6px}
        input{width:100%;padding:13px 14px;border:1px solid #e3cfd3;border-radius:10px;font:inherit;background:#fffafa}
        input:focus{outline:2px solid var(--rose);border-color:transparent}
        .password{display:flex;gap:8px}.password button{border:1px solid #e3cfd3;background:#fff;border-radius:10px;padding:0 12px;cursor:pointer;font:inherit;font-size:13px}
        .error{color:#b3261e;font-size:13px;margin:6px 0 0}
        .btn{display:block;width:100%;border:0;cursor:pointer;background:var(--gold);color:var(--dark);border-radius:10px;padding:15px 22px;font:inherit;font-weight:800;text-align:center;margin-top:8px}
        .btn[disabled]{opacity:.7}
        .note{font-size:12px;color:#896d78;text-align:center;margin:10px 0 0}.signin{text-align:center;font-size:14px;margin-top:16px}.signin a{color:var(--wine);font-weight:700}
        @media(max-width:860px){.signup{grid-template-columns:1fr}.row{grid-template-columns:1fr}.card{padding:26px}}
    </style>
    @livewireStyles
</head>
<body>
    <nav class="nav">
        <a href="{{ route('bellana.landing') }}" class="brand" aria-label="Bellana, voltar ao início">bella<span>na.</span></a>
        <a class="login" href="{{ \Filament\Facades\Filament::getPanel(\App\Support\Segment::panelId('salon'))->getLoginUrl() }}">Já tenho uma conta ↗</a>
    </nav>
    <main>{{ $slot }}</main>
    @livewireScripts
</body>
</html>
