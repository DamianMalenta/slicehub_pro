# PROJEKT: SLICEHUB PRO (ENTERPRISE/SAAS)

# DOKUMENTACJA ARCHITEKTONICZNA I STRATEGICZNA V3



## 1. KONSTYTUCJA ARCHITEKTONICZNA V3

Niniejsze zasady są nadrzędne i niepodważalne dla rozwoju systemu:



1. INFRASTRUKTURA:

- Architektura kontenerowa (Docker).

- Stack: MariaDB, PHP 8+, Redis, Node.js.

- Serwer VPS (całkowity zakaz hostingów współdzielonych).



2. ARCHITEKTURA (DDD):

- Podział na 5 wyizolowanych Silosów:

- Frontline (POS)

- Supply Chain (Magazyn)

- Omnichannel (Delivery)

- Growth (Marketing)

- Backoffice



3. KOMUNIKACJA:

- Absolutny zakaz zapytań SQL (JOIN, UPDATE) między Silosami.

- Odczyt: Wyłącznie przez REST API.

- Zapis: Wyłącznie przez zdarzenia Redis Streams / SQL Outbox.



4. RYGOR DANYCH (Mirror Validation):

- Klucze techniczne, ID, SKU, ścieżki -> format ASCII.

- Opisy i nazwy -> format UTF-8.



5. MULTI-TENANT:

- Gotowość na franczyzę od dnia zero.

- Każde zapytanie musi bezwzględnie zawierać `tenant_id`.



---



## 2. STRATEGIA WDROŻENIA GEMINI (AI INTEGRATION)

Cel: Wykorzystanie najwyższej subskrypcji Gemini w celu optymalizacji procesów biznesowych.



A. SUPPLY CHAIN (MAGAZYN):

- Wykorzystanie modeli AI do zaawansowanej analityki predykcyjnej stanów magazynowych.

B. GROWTH (MARKETING):

- Generatywne tworzenie kampanii dla franczyzobiorców w oparciu o trendy z danych `tenant_id`.

C. BACKOFFICE:

- Inteligentne parsowanie faktur i dokumentacji technicznej z zachowaniem rygoru ASCII/UTF-8.



---



## 3. NOTATKA OPERACYJNA I POSTULATY (WŁAŚCICIEL -> ARCHITEKT)



Aktualnie proces projektowy opiera się na moich notatkach manualnych, co generuje ryzyko pominięcia istotnych detali przy tak rygorystycznej architekturze.



WYMAGANIA:

1. Profesjonalizacja dokumentacji: Przeniesienie wizji z notatnika do zautomatyzowanego środowiska (np. dokumentacja techniczna generowana przez AI).

2. Weryfikacja AI: Narzędzia Gemini mają służyć jako "strażnik" Konstytucji V3 – sprawdzanie, czy nowe pomysły nie łamią zasad (np. zakazu JOIN między Silosami).

3. Eliminacja wąskich gardeł: Wykorzystanie najwyższych modeli Gemini do tworzenia User Stories bezpośrednio z moich założeń biznesowych.


Nasz obecny schemat bazy danych dla modułu, nad którym pracujemy, składa się z kilku kluczowych tabel, które wypracowaliśmy i zweryfikowaliśmy w tej sesji:

sh_menu_items (Główna Tabela Dań): To serce sprzedaży. Posiada żelazne zasady z Konstytucji V3. Najważniejsze kolumny to:

id, tenant_id (Gotowość na franczyzę), category_id.

ascii_key (Nasz niezmienny identyfikator SKU, np. ITM_MARGHERITA).

name (Nazwa UTF-8).

price (Cena bazowa w głównej tabeli!).

vat_rate_dine_in oraz vat_rate_takeaway (Nasz genialny system podwójnego VAT-u dla Omnichannel).

printer_group (Kierowanie bonów na odpowiednią drukarkę, domyślnie KITCHEN_1).

is_active, is_deleted (Zarządzanie statusem na POS).

sys_items (Główny Magazyn Surowców): Tabela, do której zrobiliśmy dziś rano "zastrzyk inteligencji", dodając kolumnę search_aliases (synonimy do AutoScanu, np. mąka, mąką, mące).

sh_recipes oraz sh_recipe_steps: Tabele Bliźniaka Cyfrowego, w których przypisujemy surowce z magazynu do ascii_key dania.


MAMY NAJDROZSZA SUBSKRYPCJE SZKODA JEJ NIE WYKORZYSTAĆ. TYLKO JA NIE MAM DOSWIADCZENIA