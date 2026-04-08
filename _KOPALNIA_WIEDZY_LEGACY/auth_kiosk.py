def verify_kiosk_pin(entered_pin):
    # Ścisła walidacja: tylko cyfry, format ASCII (żadnych złożonych znaków)
    if not entered_pin.isascii() or not entered_pin.isdigit():
        return False, "Błąd: Niedozwolony format PIN"
    
    # Izolowane sprawdzenie w zmapowanej bazie PIN-ów
    # ...
    return True, "Dostęp do Kiosku przyznany"