<?php

namespace App\Services\Spiir;

use App\Enums\ExpenseGroup;

final class ExpenseGroupMapping
{
    /**
     * Spiir source IDs, guarded by the original name and parent source ID.
     *
     * @var array<string, array{string, string, ExpenseGroup}>
     */
    private const array SUBCATEGORIES = [
        '114' => ['Boliglån/husleje', '12', ExpenseGroup::Bills],
        '115' => ['El, vand, varme & renovation', '12', ExpenseGroup::Bills],
        '116' => ['Ejerforening', '12', ExpenseGroup::Bills],
        '117' => ['Ejendomsskat', '12', ExpenseGroup::Bills],
        '118' => ['Husforsikring', '12', ExpenseGroup::Bills],
        '119' => ['Indbo- & familieforsikring', '12', ExpenseGroup::Bills],
        '120' => ['Alarmsystem', '12', ExpenseGroup::Bills],
        '121' => ['Udgifter fritidshus', '12', ExpenseGroup::Other],
        '122' => ['Ombygning & vedligehold', '12', ExpenseGroup::LivingCosts],
        '123' => ['Bil-, MC-, bådlån o.l.', '13', ExpenseGroup::Bills],
        '124' => ['Brændstof', '13', ExpenseGroup::LivingCosts],
        '125' => ['Bilforsikring & autohjælp', '13', ExpenseGroup::Bills],
        '126' => ['Ejerafgift/grøn afgift', '13', ExpenseGroup::Bills],
        '127' => ['Bus, tog, færge o.l.', '13', ExpenseGroup::LivingCosts],
        '128' => ['Taxi', '13', ExpenseGroup::Lifestyle],
        '129' => ['Parkering', '13', ExpenseGroup::LivingCosts],
        '130' => ['Værksted & reservedele', '13', ExpenseGroup::LivingCosts],
        '131' => ['Anden transport', '13', ExpenseGroup::Other],
        '132' => ['Dagligvarer', '14', ExpenseGroup::LivingCosts],
        '133' => ['Kiosk, bager & specialbutikker', '14', ExpenseGroup::LivingCosts],
        '134' => ['Apotek & medicin', '16', ExpenseGroup::LivingCosts],
        '135' => ['Fly & Hotel', '15', ExpenseGroup::Lifestyle],
        '138' => ['Billeje', '15', ExpenseGroup::Lifestyle],
        '139' => ['Sommerhus & camping', '15', ExpenseGroup::Lifestyle],
        '140' => ['Ferieaktiviteter', '15', ExpenseGroup::Lifestyle],
        '141' => ['Rejseforsikring', '15', ExpenseGroup::Lifestyle],
        '142' => ['Underholds- & børnebidrag', '16', ExpenseGroup::Bills],
        '143' => ['Institution', '16', ExpenseGroup::Bills],
        '144' => ['Fagforening & a-kasse', '16', ExpenseGroup::Bills],
        '145' => ['Livs- & ulykkesforsikring', '16', ExpenseGroup::Bills],
        '146' => ['Sundheds- & sygeforsikring', '16', ExpenseGroup::Bills],
        '147' => ['Sport & fritid', '17', ExpenseGroup::Lifestyle],
        '148' => ['TV & streaming', '16', ExpenseGroup::Bills],
        '149' => ['Telefoni & internet', '16', ExpenseGroup::Bills],
        '151' => ['Hus & havehjælp', '17', ExpenseGroup::Lifestyle],
        '153' => ['Behandling & læger', '16', ExpenseGroup::LivingCosts],
        '154' => ['Briller & kontaktlinser', '16', ExpenseGroup::LivingCosts],
        '155' => ['Fastfood & takeaway', '17', ExpenseGroup::Lifestyle],
        '156' => ['Bar, cafe & restaurant', '17', ExpenseGroup::Lifestyle],
        '157' => ['Tøj, sko & accessories', '17', ExpenseGroup::LivingCosts],
        '158' => ['Møbler & boligudstyr', '17', ExpenseGroup::LivingCosts],
        '159' => ['Elektronik & computerudstyr', '17', ExpenseGroup::Lifestyle],
        '160' => ['Spil & legetøj', '17', ExpenseGroup::Lifestyle],
        '161' => ['Hobby & sportsudstyr', '17', ExpenseGroup::Lifestyle],
        '162' => ['Frisør & personlig pleje', '17', ExpenseGroup::LivingCosts],
        '163' => ['Film, musik & læsestof', '17', ExpenseGroup::Lifestyle],
        '164' => ['Biograf, koncerter & forlystelser', '17', ExpenseGroup::Lifestyle],
        '165' => ['Tips & lotto', '17', ExpenseGroup::Lifestyle],
        '167' => ['Babyudstyr', '17', ExpenseGroup::LivingCosts],
        '168' => ['Kæledyr', '17', ExpenseGroup::LivingCosts],
        '169' => ['Gaver & velgørenhed', '17', ExpenseGroup::Lifestyle],
        '170' => ['Andet privatforbrug', '17', ExpenseGroup::Other],
        '171' => ['Ukendt', '18', ExpenseGroup::Other],
        '172' => ['Kontanthævning & check', '17', ExpenseGroup::Other],
        '174' => ['Bankgebyrer', '18', ExpenseGroup::Other],
        '175' => ['Rykkergebyrer', '18', ExpenseGroup::Other],
        '176' => ['Bøder & afgifter', '18', ExpenseGroup::Other],
        '177' => ['Restskat', '18', ExpenseGroup::Other],
        '178' => ['Studielån', '19', ExpenseGroup::Bills],
        '179' => ['Forbrugslån', '19', ExpenseGroup::Bills],
        '180' => ['Private lån (venner & familie)', '19', ExpenseGroup::Bills],
        '181' => ['Udlånsrenter', '19', ExpenseGroup::Bills],
        '186' => ['Online services & software', '17', ExpenseGroup::Lifestyle],
        '187' => ['Andre boligudgifter', '12', ExpenseGroup::Other],
        '188' => ['Tobak & alkohol', '17', ExpenseGroup::Lifestyle],
        '189' => ['Studieudgifter', '16', ExpenseGroup::LivingCosts],
        '191' => ['Foreninger & kontingenter', '16', ExpenseGroup::Bills],
        '192' => ['Kantine- & frokostordning', '14', ExpenseGroup::LivingCosts],
        '193' => ['Højskole- & kursusophold', '17', ExpenseGroup::Lifestyle],
        '194' => ['Serviceydelser & rådgivning', '17', ExpenseGroup::Other],
        '195' => ['Have & planter', '12', ExpenseGroup::LivingCosts],
        '196' => ['Offentligt gebyr', '18', ExpenseGroup::Other],
    ];

    public static function forSubcategory(?string $legacyId, string $name, ?string $categoryLegacyId): ?ExpenseGroup
    {
        $mapping = self::SUBCATEGORIES[$legacyId ?? ''] ?? null;

        return $mapping !== null && $mapping[0] === $name && $mapping[1] === $categoryLegacyId
            ? $mapping[2]
            : null;
    }
}
