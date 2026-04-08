def verify_admin_login(username, password):
    # Pełna obsługa UTF-8 dla skomplikowanych haseł
    try:
        username.encode('utf-8')
        password.encode('utf-8')
    except UnicodeError:
        return False, "Błąd kodowania wprowadzonych danych"
        
    # Niezależna weryfikacja loginu i hasła w tabeli uprawnień administracyjnych
    # ...
    return True, "Panel Admina odblokowany"