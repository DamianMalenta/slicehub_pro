-- =============================================================================
-- SliceHub Pro V2 — Migration 004: search_aliases column + Polish alias corpus
-- Run AFTER 001_init_slicehub_pro_v2.sql (or after seed_demo_all.php for full data)
--
-- 1. Adds search_aliases + is_active + is_deleted columns to sys_items
--    (safe: uses IF NOT EXISTS via procedure).
-- 2. Populates aliases for 30+ pizzeria/burger ingredients with Polish
--    grammatical forms (declensions, diminutives, synonyms).
-- =============================================================================

USE slicehub_pro_v2;
SET NAMES utf8mb4;

-- ─── SCHEMA PATCH ────────────────────────────────────────────────────────────
-- sys_items in migration 001 only has (id, tenant_id, sku, name, base_unit).
-- The backend now expects search_aliases, is_active, is_deleted.

ALTER TABLE sys_items
  ADD COLUMN IF NOT EXISTS search_aliases VARCHAR(512) DEFAULT NULL
    COMMENT 'Comma-separated Polish aliases/declensions for AutoScan',
  ADD COLUMN IF NOT EXISTS is_active  TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0;

-- ─── ALIAS CORPUS ────────────────────────────────────────────────────────────
-- Format: lowercase, comma-separated, all common Polish declensions
-- + colloquial synonyms used in menu descriptions and speech.

-- 1. Mąka
UPDATE sys_items SET search_aliases = 'mąka,mąki,mąką,mące,mąkę,maka,maki,maka,flour,tipo,ciasto'
WHERE sku = 'MKA_TIPO00' AND (search_aliases IS NULL OR search_aliases = '');

-- 2. Mozzarella
UPDATE sys_items SET search_aliases = 'mozzarella,mozzarelli,mozzarellą,mozarella,mozarelli,ser,sera,serem,fior di latte'
WHERE sku = 'SER_MOZZ' AND (search_aliases IS NULL OR search_aliases = '');

-- 3. Sos pomidorowy
UPDATE sys_items SET search_aliases = 'sos pomidorowy,sosu pomidorowego,sosem pomidorowym,pomidor,pomidorów,pomidory,pomidorowy,pomidorowa,passata,san marzano,sugo,marinara,napoli'
WHERE sku = 'SOS_POM' AND (search_aliases IS NULL OR search_aliases = '');

-- 4. Oliwa
UPDATE sys_items SET search_aliases = 'oliwa,oliwy,oliwą,oliwę,oliwie,oliwka,olej,oleju,olejem,extra virgin'
WHERE sku = 'OLJ_OLIWA' AND (search_aliases IS NULL OR search_aliases = '');

-- 5. Drożdże
UPDATE sys_items SET search_aliases = 'drożdże,drożdży,drożdżami,drożdżom,drozdze,yeast'
WHERE sku = 'DRZ_SUCHE' AND (search_aliases IS NULL OR search_aliases = '');

-- 6. Sól
UPDATE sys_items SET search_aliases = 'sól,soli,solą,solę,sol,salt'
WHERE sku = 'SOL_MORSKA' AND (search_aliases IS NULL OR search_aliases = '');

-- 7. Pepperoni / Salami
UPDATE sys_items SET search_aliases = 'pepperoni,peperoni,salami,salame,kiełbasa,kielbasa,kiełbasą,pikantne,spicy'
WHERE sku = 'PEPP_SALAMI' AND (search_aliases IS NULL OR search_aliases = '');

-- 8. Szynka parmeńska
UPDATE sys_items SET search_aliases = 'szynka,szynki,szynką,szynce,szynkę,prosciutto,parmeńska,parmenska,parma,ham,wędlina,wedlina'
WHERE sku = 'SZYNKA_PARM' AND (search_aliases IS NULL OR search_aliases = '');

-- 9. Pieczarki
UPDATE sys_items SET search_aliases = 'pieczarki,pieczarek,pieczarkami,pieczarką,pieczarka,pieczarkach,grzyby,grzybami,grzybów,grzyb,champignon,mushroom,fungi,funghi'
WHERE sku = 'PIECZARKI' AND (search_aliases IS NULL OR search_aliases = '');

-- 10. Cebula
UPDATE sys_items SET search_aliases = 'cebula,cebuli,cebulą,cebulę,cebule,cebulami,cebulowy,cebulowa,cebulowe,onion'
WHERE sku = 'CEBULA' AND (search_aliases IS NULL OR search_aliases = '');

-- 11. Ananas
UPDATE sys_items SET search_aliases = 'ananas,ananasa,ananasem,ananasie,ananasy,ananasów,pineapple,hawajska'
WHERE sku = 'ANANAS' AND (search_aliases IS NULL OR search_aliases = '');

-- 12. Gorgonzola
UPDATE sys_items SET search_aliases = 'gorgonzola,gorgonzoli,gorgonzolą,pleśniowy,plesniowy,blue cheese,ser pleśniowy'
WHERE sku = 'SER_GORG' AND (search_aliases IS NULL OR search_aliases = '');

-- 13. Parmezan
UPDATE sys_items SET search_aliases = 'parmezan,parmezanu,parmezanem,parmigiano,grana padano,grana,padano,reggiano'
WHERE sku = 'SER_PARM' AND (search_aliases IS NULL OR search_aliases = '');

-- 14. Cheddar
UPDATE sys_items SET search_aliases = 'cheddar,cheddara,cheddarem,cheddarze,żółty ser,zolty ser'
WHERE sku = 'SER_CHEDDAR' AND (search_aliases IS NULL OR search_aliases = '');

-- 15. Jalapeño
UPDATE sys_items SET search_aliases = 'jalapeno,jalapeño,jalapenos,papryczki,papryczka,papryczek,papryczkami,chili,ostra papryka'
WHERE sku = 'JALAPENO' AND (search_aliases IS NULL OR search_aliases = '');

-- 16. Oliwki
UPDATE sys_items SET search_aliases = 'oliwki,oliwek,oliwkami,oliwką,oliwka,oliwkach,olives,czarne oliwki'
WHERE sku = 'OLIWKI_CZ' AND (search_aliases IS NULL OR search_aliases = '');

-- 17. Kurczak
UPDATE sys_items SET search_aliases = 'kurczak,kurczaka,kurczakiem,kurczakowi,kurczaku,kurczaki,kura,kurę,chicken,filet,filety,drób,drob,drobiowy,grillowany'
WHERE sku = 'KURCZAK' AND (search_aliases IS NULL OR search_aliases = '');

-- 18. Sos BBQ
UPDATE sys_items SET search_aliases = 'bbq,barbecue,barbeque,sos bbq,sosu bbq,sosem bbq,grill,grillowy'
WHERE sku = 'SOS_BBQ' AND (search_aliases IS NULL OR search_aliases = '');

-- 19. Bułka burgerowa
UPDATE sys_items SET search_aliases = 'bułka,bulka,bułki,bulki,bułką,bulka,brioche,bun,burger bun,kajzerka'
WHERE sku = 'BULKA_BURG' AND (search_aliases IS NULL OR search_aliases = '');

-- 20. Wołowina mielona
UPDATE sys_items SET search_aliases = 'wołowina,wolowina,wołowiny,wolowiny,wołowiną,wołowinę,mielone,mielonego,mielona,beef,kotlet,patty,burger mięso,mieso'
WHERE sku = 'WOLOWINA_M' AND (search_aliases IS NULL OR search_aliases = '');

-- 21. Sałata
UPDATE sys_items SET search_aliases = 'sałata,salata,sałaty,salaty,sałatą,salatą,rzymska,iceberg,lettuce,zielona'
WHERE sku = 'SALATA_RZY' AND (search_aliases IS NULL OR search_aliases = '');

-- 22. Pomidory świeże
UPDATE sys_items SET search_aliases = 'pomidor,pomidora,pomidorem,pomidorze,pomidory,pomidorów,tomato,świeże pomidory,plasterki pomidora'
WHERE sku = 'POMIDOR' AND (search_aliases IS NULL OR search_aliases = '');

-- 23. Ogórek kiszony
UPDATE sys_items SET search_aliases = 'ogórek,ogorek,ogórka,ogorka,ogórkiem,ogórki,ogorki,kiszony,kiszone,korniszon,korniszony,pickle'
WHERE sku = 'OGOREK_KIS' AND (search_aliases IS NULL OR search_aliases = '');

-- 24. Sos czosnkowy
UPDATE sys_items SET search_aliases = 'czosnek,czosnku,czosnkiem,czosnkowy,czosnkowa,czosnkowe,czosnkowym,czosnkowego,garlic,aioli'
WHERE sku = 'SOS_CZOSN' AND (search_aliases IS NULL OR search_aliases = '');

-- 25. Sos ostry
UPDATE sys_items SET search_aliases = 'ostry,ostra,ostre,ostrego,ostrym,chili,hot,tabasco,sriracha,pikantny,pikantna,pikantne'
WHERE sku = 'SOS_OSTRY' AND (search_aliases IS NULL OR search_aliases = '');

-- 26. Makaron spaghetti
UPDATE sys_items SET search_aliases = 'spaghetti,spagetti,spag,makaron,makaronu,makaronem,pasta,bolognese,carbonara,aglio olio'
WHERE sku = 'MAKARON_SPAG' AND (search_aliases IS NULL OR search_aliases = '');

-- 27. Makaron penne
UPDATE sys_items SET search_aliases = 'penne,rigate,rurki,makaron penne,arrabiata,arrabbiata'
WHERE sku = 'MAKARON_PENN' AND (search_aliases IS NULL OR search_aliases = '');

-- 28. Płaty lasagne
UPDATE sys_items SET search_aliases = 'lasagne,lasagna,lazania,lazanii,lazanią,płaty,platy'
WHERE sku = 'MAKARON_LAS' AND (search_aliases IS NULL OR search_aliases = '');

-- 29. Feta
UPDATE sys_items SET search_aliases = 'feta,fety,fetą,fetę,fecie,grecki,grecka,greckie,ser grecki,biały ser'
WHERE sku = 'FETA' AND (search_aliases IS NULL OR search_aliases = '');

-- 30. Frytki
UPDATE sys_items SET search_aliases = 'frytki,frytek,frytkami,frytką,fries,french fries,ziemniaki,ziemniak,kartofle'
WHERE sku = 'FRYTKI_MRZ' AND (search_aliases IS NULL OR search_aliases = '');

-- 31. Nuggetsy
UPDATE sys_items SET search_aliases = 'nuggets,nuggetsy,nuggetsów,nuggetsami,nugget,stripsy,kawałki kurczaka,panierka'
WHERE sku = 'NUGGETS_MRZ' AND (search_aliases IS NULL OR search_aliases = '');

-- 32. Krążki cebulowe
UPDATE sys_items SET search_aliases = 'krążki,krazki,krążków,krazkow,krążkami,onion rings,rings,cebulowe'
WHERE sku = 'KRAZKI_CEB' AND (search_aliases IS NULL OR search_aliases = '');

-- 33. Mascarpone
UPDATE sys_items SET search_aliases = 'mascarpone,mascarponu,mascarponem,tiramisu'
WHERE sku = 'MASCARPONE' AND (search_aliases IS NULL OR search_aliases = '');

-- 34. Śmietanka
UPDATE sys_items SET search_aliases = 'śmietanka,smietanka,śmietanki,smietanki,śmietanką,śmietana,smietana,cream,krem'
WHERE sku = 'SMIETANKA_30' AND (search_aliases IS NULL OR search_aliases = '');

-- 35. Cukier
UPDATE sys_items SET search_aliases = 'cukier,cukru,cukrem,cukrze,sugar,słodzik,slodzik'
WHERE sku = 'CUKIER' AND (search_aliases IS NULL OR search_aliases = '');

-- 36. Bazylia
UPDATE sys_items SET search_aliases = 'bazylia,bazylii,bazylią,bazylię,basil,zioła,ziola,świeża bazylia'
WHERE sku = 'BAZYLIA_SW' AND (search_aliases IS NULL OR search_aliases = '');

-- 37. Coca-Cola
UPDATE sys_items SET search_aliases = 'cola,coca,coca-cola,cocacola,coke,pepsi,napój gazowany,napoj gazowany'
WHERE sku = 'COCA_COLA_05' AND (search_aliases IS NULL OR search_aliases = '');

-- 38. Sprite
UPDATE sys_items SET search_aliases = 'sprite,7up,cytrynowy,lemon,lime'
WHERE sku = 'SPRITE_05' AND (search_aliases IS NULL OR search_aliases = '');

-- 39. Woda
UPDATE sys_items SET search_aliases = 'woda,wody,wodą,wodę,water,mineralna,niegazowana,gazowana'
WHERE sku = 'WODA_05' AND (search_aliases IS NULL OR search_aliases = '');

-- 40. Sok pomarańczowy
UPDATE sys_items SET search_aliases = 'sok,soku,sokiem,pomarańczowy,pomaranczowy,orange,juice'
WHERE sku = 'SOK_POM_1L' AND (search_aliases IS NULL OR search_aliases = '');

-- 41. Piwo
UPDATE sys_items SET search_aliases = 'piwo,piwa,piwem,piwie,tyskie,lager,beer,browar'
WHERE sku = 'PIWO_TYSKIE' AND (search_aliases IS NULL OR search_aliases = '');

-- 42. Opakowanie pizza
UPDATE sys_items SET search_aliases = 'karton,kartonu,kartonem,opakowanie,pudełko,pudelko,box pizza'
WHERE sku = 'OPAK_PIZZA' AND (search_aliases IS NULL OR search_aliases = '');

-- 43. Opakowanie burger
UPDATE sys_items SET search_aliases = 'styropian,styropianu,opakowanie burger,box burger,pojemnik'
WHERE sku = 'OPAK_BURGER' AND (search_aliases IS NULL OR search_aliases = '');
