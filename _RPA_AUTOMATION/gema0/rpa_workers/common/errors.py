"""Wyjatki domenowe workerow GEMA-0."""


class RpaError(Exception):
    """Baza bledow RPA."""


class BadPayloadError(RpaError):
    """Zly payload na wejsciu workera."""


class WindowNotFoundError(RpaError):
    """Nie znaleziono okna Cursor pasujacego do etykiety."""


class FocusLostError(RpaError):
    """Focus zostal utracony w trakcie sekwencji."""


class ClipboardError(RpaError):
    """Operacja na schowku nie powiodla sie."""


class PanicStop(RpaError):
    """Operator wywolal panic-stop."""
