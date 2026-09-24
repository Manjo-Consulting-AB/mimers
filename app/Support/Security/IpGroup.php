<?php

namespace App\Support\Security;

/**
 * Pseudonymen en IP-adress skrivs som — se [[ADR-0043 Tre loggar]]
 * § Säkerhetsloggen och [[ADR-0017 Missbruksvektorer]] § Mätningen.
 *
 * **Formeln flyttade hit, den ändrades inte.** Den har stått i
 * App\Console\ReportsAbuseSignals sedan issue 50b, och rapporten använder
 * fortfarande samma värde: de första sexton hexatecknen av
 * `hash_hmac('sha256', $ip, config('app.key'))`. Att de två räknar fram
 * pseudonymen ur samma klass är hela poängen — glider de isär pekar
 * säkerhetsloggens grupper och rapportens grupper på olika saker, och ingen
 * av dem går att jämföra med den andra. Rapportens utdata är därför byte för
 * byte densamma som före utbrytningen.
 *
 * **Nyckeln är det som gör pseudonymen till en pseudonym.** En vanlig hash av
 * en IPv4-adress går att vända genom att pröva alla fyra miljarder
 * adresserna; med `APP_KEY` som nyckel går det inte. Ett byte av `APP_KEY`
 * bryter kopplingen mellan gamla och nya rader, precis som för rapporten.
 *
 * Sexton tecken är sextiofyra bitar. Det räcker för att två adresser inte ska
 * kollidera av misstag, och det är samma längd som rapporten redan loggar
 * (issue 115 gallrar raderna efter tolv månader, inte pseudonymerna).
 */
class IpGroup
{
    /**
     * Antalet hexatecken som behålls — sextiofyra bitar. Ändras den här
     * konstanten ändras rapportens utdata, och då är det ett beslut och inte
     * en detalj.
     */
    public const LENGTH = 16;

    /**
     * Pseudonymen för en rå IP-adress. Samma adress ger samma grupp mellan
     * två körningar; nyckeln gör att gruppen inte kan räknas fram utan
     * `config('app.key')`.
     */
    public static function from(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, self::LENGTH);
    }
}
