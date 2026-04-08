<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SliceHub POS - Moduł IN (MAX)</title>
    <style>
        :root { --primary: #f59e0b; --dark: #111827; --gray: #f3f4f6; --text: #374151; --danger: #ef4444; --success: #10b981; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #e5e7eb; color: var(--text); margin: 0; padding: 20px; }
        .dashboard { max-width: 1400px; margin: 0 auto; display: grid; grid-template-columns: 2.5fr 1fr; gap: 20px; }
        
        /* Karty i Nagłówki */
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        .header { background: var(--dark); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { margin: 0; font-size: 22px; }
        .badge { background: var(--primary); color: white; padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; }

        /* Strefa Pracownika */
        .scan-area { background: var(--dark); padding: 30px; border-radius: 12px; text-align: center; margin-bottom: 20px; }
        .scan-input { width: 80%; padding: 15px; font-size: 20px; border-radius: 8px; border: none; text-align: center; }
        
        .progress-bar { background: var(--gray); height: 10px; border-radius: 5px; overflow: hidden; margin: 15px 0; }
        .progress-fill { background: var(--primary); width: 35%; height: 100%; }

        .table-ui { width: 100%; border-collapse: collapse; }
        .table-ui th { background: var(--gray); padding: 12px; text-align: left; font-size: 13px; color: #6b7280; text-transform: uppercase; }
        .table-ui td { padding: 15px 12px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
        .input-inline { width: 100px; padding: 8px; border: 2px solid var(--primary); border-radius: 6px; font-size: 16px; font-weight: bold; text-align: center; }
        
        .status-diff { font-weight: bold; padding: 4px 8px; border-radius: 6px; font-size: 14px; }
        .diff-ok { background: #d1fae5; color: var(--success); }
        .diff-bad { background: #fee2e2; color: var(--danger); }
        .diff-blind { background: #e5e7eb; color: #6b7280; }

        .btn-massive { display: block; width: 100%; background: var(--dark); color: white; padding: 18px; text-align: center; border: none; border-radius: 8px; font-size: 18px; font-weight: bold; cursor: pointer; margin-top: 20px; transition: 0.2s; }
        .btn-massive:hover { background: #1f2937; }

        /* Strefa Admina */
        .admin-panel { border-top: 4px solid var(--primary); }
        .setting-group { margin-bottom: 25px; }
        .setting-group h4 { margin: 0 0 10px 0; color: var(--dark); border-bottom: 1px solid #e5e7eb; padding-bottom: 5px; }
        
        .toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; }
        .toggle-label { font-size: 13px; font-weight: 500; }
        
        /* Switch CSS */
        .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--primary); }
        input:checked + .slider:before { transform: translateX(20px); }
    </style>
</head>
<body>

<div class="header">
    <div>
        <h1>SLICEHUB POS v3.1 Pro</h1>
        <div style="color: #9ca3af; font-size: 14px; margin-top: 5px;">Zalogowany: Admin | Baza: sh_products</div>
    </div>
    <span class="badge">Moduł: Inwentaryzacja (IN) - TRWA</span>
</div>

<div class="dashboard">
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="margin:0;">Spis z natury: Magazyn Główny</h2>
            <span style="font-weight: bold; color: var(--primary);">Policzono: 45 / 128 poz.</span>
        </div>
        
        <div class="progress-bar"><div class="progress-fill"></div></div>

        <div class="scan-area">
            <input type="text" class="scan-input" placeholder="Wpisz nazwę, SKU lub zeskanuj kod kreskowy..." autofocus>
        </div>

        <table class="table-ui">
            <thead>
                <tr>
                    <th style="width: 40%;">Produkt (UTF-8)</th>
                    <th style="width: 20%; text-align: center;">Stan Systemowy</th>
                    <th style="width: 20%; text-align: center;">Stan Faktyczny (Wpisz)</th>
                    <th style="width: 20%; text-align: right;">Różnica</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>Ser Mozzarella (Blok)</strong>
                        <div style="font-size: 11px; color: #9ca3af;">SKU: SER_MOZ_BLOK</div>
                    </td>
                    <td style="text-align: center; color: #9ca3af;">[ UKRYTO ]</td>
                    <td style="text-align: center;"><input type="number" class="input-inline" value="12.5"></td>
                    <td style="text-align: right;"><span class="status-diff diff-blind">?</span></td>
                </tr>
                <tr>
                    <td>
                        <strong>Sos Pomidorowy (Puszka 5L)</strong>
                        <div style="font-size: 11px; color: #9ca3af;">SKU: SOS_POM_5L</div>
                    </td>
                    <td style="text-align: center; color: #9ca3af;">[ UKRYTO ]</td>
                    <td style="text-align: center;"><input type="number" class="input-inline" value="8"></td>
                    <td style="text-align: right;"><span class="status-diff diff-blind">?</span></td>
                </tr>
                 <tr>
                    <td>
                        <strong>Oliwa z Oliwek Extra Virgin</strong>
                        <div style="font-size: 11px; color: #9ca3af;">SKU: OLIWA_EV_1L</div>
                    </td>
                    <td style="text-align: center; color: #9ca3af;">[ UKRYTO ]</td>
                    <td style="text-align: center;"><input type="number" class="input-inline" placeholder="0"></td>
                    <td style="text-align: right;">-</td>
                </tr>
            </tbody>
        </table>

        <button class="btn-massive">Zakończ i Prześlij do Weryfikacji</button>
    </div>

    <div class="card admin-panel">
        <h2 style="margin-top:0;">Konfiguracja Inwentaryzacji</h2>
        <p style="font-size: 12px; color: #6b7280; margin-bottom: 20px;">Wymusza konkretne zachowania modułu i pracowników podczas spisu.</p>

        <div class="setting-group">
            <h4>Zasady Spisu</h4>
            <div class="toggle-row">
                <span class="toggle-label">Inwentaryzacja Ślepa (ukryj stany)</span>
                <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
            </div>
            <div class="toggle-row">
                <span class="toggle-label">Zablokuj sprzedaż (POS) podczas spisu</span>
                <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
            </div>
            <div class="toggle-row">
                <span class="toggle-label">Wymagaj podwójnego liczenia (2 osoby)</span>
                <label class="switch"><input type="checkbox"><span class="slider"></span></label>
            </div>
        </div>

        <div class="setting-group">
            <h4>Rozliczanie Różnic</h4>
            <div class="toggle-row">
                <span class="toggle-label">Automatycznie wyrównaj stany na koniec</span>
                <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
            </div>
            <div class="toggle-row">
                <span class="toggle-label">Generuj dokumenty RW/PW dla różnic</span>
                <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
            </div>
            <div class="toggle-row">
                <span class="toggle-label">Manko pow. 100 PLN wymaga pinu ROOT</span>
                <label class="switch"><input type="checkbox" checked><span class="slider"></span></label>
            </div>
        </div>
        
    </div>
</div>

</body>
</html>