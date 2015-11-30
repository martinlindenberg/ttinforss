Einfach die config.json anpassen und den Parser laufen lassen.

# leagues
    alle genannten ligen werden in einem durchlauf in einer rss-datei ausgelesen

# url
    domain: muss in der regel nicht mehr geändert werden, die zieldomain zum jeweiligen spielplan
    placeholder: platzhalter der für die liga eingesetzt wird (siehe url, da ist er drin)

# keywords
    filtert das ergebnis nach dieser mannschaft, oder mannschaften
    leer zeigt alle spiele aller mannschaften
    mehrere werte sucht nach mehreren mannschaften "mannschaft1", "mannschaft2"

# startfromtoday
    true blendet alles aus was älter als heute ist
    false zeigt alle spiele der saison, auch ergebnisse

# orderbykey
    sortiert das ergebnis nach diesem key
        erlaubte werte
            date - alles nach datum
            none - eher unsortiert
# ttl
    cachezeit in sekunden