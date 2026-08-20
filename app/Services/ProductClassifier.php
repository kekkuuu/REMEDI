<?php

namespace App\Services;

/**
 * Works out which category a product belongs in, from its name alone.
 *
 * The supplier master file's own Category column is close to useless: it
 * reads like a naive substring match over the product name, which is how
 * "GATSBY WAX WATERGLOSS" ended up under Water & Beverages, "TRUST CONDOM
 * CHOCOLATE" under Snacks & Confectionery, "J&J BABY SOAP MILK" under Milk
 * & Dairy, and 170 deodorants and shampoos under Medicine / Pharmaceutical.
 *
 * That last one is not cosmetic: Product::$is_medicine drives the 90-120 day
 * supplier return window (see ProductBatch::getReturnStatusAttribute), so a
 * body spray filed as medicine inherits a drug's return rules.
 *
 * The rules below are ORDERED and first-match-wins, because the signals
 * overlap constantly:
 *
 *   - "MYRA E 400IU"       is a vitamin, "MYRA LOTION" is a toiletry.
 *   - "CREAM CONES"        is ice cream, "BETNOVATE CREAM" is a corticosteroid.
 *   - "J&J BABY SOAP MILK" is baby care, "ALASKA POWDERED MILK" is dairy.
 *   - "HOT WATER BAG"      is a device, "WILKINS DISTILLED" is drinking water.
 *
 * So the narrow, unambiguous buckets (devices, supplements, baby, household)
 * are resolved before the broad ones (personal care, then medicine).
 *
 * A name that matches nothing returns NULL, which every caller must treat as
 * "leave this product where it is". Guessing is worse than not moving: a
 * wrong move silently changes which return window a batch is held to.
 */
class ProductClassifier
{
    public const MEDICINE = 'Medicine / Pharmaceutical';

    public const VITAMINS = 'Vitamins & Supplements';

    public const MEDICAL_SUPPLY = 'Medical Supplies & Devices';

    public const PERSONAL_CARE = 'Personal Care';

    public const BABY_CARE = 'Baby Care';

    public const HOUSEHOLD = 'Household';

    public const MILK_DAIRY = 'Milk & Dairy';

    public const BEVERAGES = 'Water & Beverages';

    public const SNACKS = 'Snacks & Confectionery';

    public const GENERAL = 'General Merchandise';

    /**
     * Categories this classifier can assign that the seeded product master
     * does not already contain. Callers that write to the database create
     * these before reclassifying.
     */
    public const NEW_CATEGORIES = [
        self::MEDICAL_SUPPLY,
        self::BABY_CARE,
        self::HOUSEHOLD,
    ];

    /**
     * A stated strength means pharmaceutical whatever the form -- the same
     * rule Product::DOSAGE_PATTERN applies, for the same reason ("NIZORAL
     * 20MG/ML SHAMPOO" is a medicated antifungal). Deliberately not a bare
     * ML: "135ML" is a bottle size, not a dose.
     */
    private const DOSAGE = '/\d+\s*(MG|MCG|IU|%)/';

    /**
     * Ordered rule table. Each rule is [category, patterns, exceptPatterns].
     *
     * A pattern is a plain substring match against the name, uppercased and
     * space-padded, unless it starts with "/" -- then it is a regex. Leading
     * and trailing spaces in a substring pattern act as word boundaries:
     * ' TP ' matches "COLGATE TP REGULAR" but not "PARACETAMOL DROPS".
     */
    private static function rules(): array
    {
        return [
            // ---------------------------------------------------------------
            // 1. Devices and consumables. Unambiguous vocabulary, and several
            //    of these ("MASK", "CANNULA", "ALCOHOL") would otherwise be
            //    swept up by the medicine rules further down.
            // ---------------------------------------------------------------
            [self::MEDICAL_SUPPLY, [
                'SYRINGE', 'GAUZE', 'BANDAGE', 'MICROPORE', 'SURGICAL TAPE',
                'BAND-AID', 'BAND AID', 'MEDIPLAST', 'GLOVES', 'THERMOMETER',
                'CANNUL', 'NEB KIT', 'NEBULIZ', 'NEBULIZER',
                'MASK', 'KF94', 'KN95',
                'ARM SLING', 'ABDOMINAL BINDER', 'ICE BAG', 'HOT WATER BAG',
                'URINE BAG', 'URINE COLLECTOR', 'URINE CONTAINER',
                'SPECIMEN CUP', 'STOOL CONTAINER',
                'PREGNANCY TEST', 'OVULATION TEST', 'CLEAR CHECK',
                'UNDERPADS', 'MACROSET', 'MICROSET', 'BUTTERFLY G',
                'INSULIN SYRINGE', 'BD INSULIN', 'LANCET', 'TEST STRIP',
                'COTTON BALL', 'COTTON BUDS', 'COTTON ROUNDS', 'HAPPY COTTON',
                'CRUTCH', 'WHEELCHAIR', 'ALCOHOL', 'HYGIENIX',
            ], [
                // Cosmetic "masks" are not PPE.
                'SLEEPING MASK', 'HAIR MASK', 'FACIAL MASK',
            ]],

            // ---------------------------------------------------------------
            // 2. Vitamins and supplements. Must beat both the dosage rule
            //    (they carry IU/MG strengths) and the beverage rule (MX3 and
            //    XANTHONE are sold as coffee sachets, BIOFIT as tea bags).
            // ---------------------------------------------------------------
            [self::VITAMINS, [
                'VITAMIN', 'MULTIVIT', ' VIT ', ' VIT.', 'ASCORBIC', 'BUCLIZINE',
                'B COMPLEX', 'REVITAPLEX', 'FOLIC', 'FERROUS', 'CALCIUM',
                'CALVIT', 'CALCIUMADE', 'BEWELL-C', ' ZINC', 'PROZINC',
                'FISH OIL', 'FISHOIL', 'OMEGA', 'COLLAGEN', 'MELATONIN',
                'GUMMIES', 'PASTILLES', 'PROBIOTIC', 'FLOTERA', 'SUPPLEMENT',
                'CENTRUM', 'ENERVON', 'CHERIFER', 'PROPAN', 'GROWEE', 'CEELIN',
                'CONZACE', 'POTENCEE', 'SCOTTS', 'FORTI-D', 'KIRKLAND',
                'WEBBER', 'MYRA E ', 'OBIMIN', 'MOSVIT', 'MEMO PLUS',
                'C-LIUM', 'BIOFIT', 'LIVER GOLD', 'SNOW CAPS', 'WELLSPRING',
                'XANTHONE', 'SANTE BARLEY', 'MX3', 'OMX', 'FLEXIPRO',
                'FERALAC', 'HELTINE', 'HEPATEK', 'APPEBON', 'APPETAMINE',
                'ALINGATONG', 'SPIRULINA', 'MALUNGGAY', 'MORINGA',
                'HONEYMOON TEA', 'MYREVIT', 'MYRA ULTIMATE',
            ], []],

            // ---------------------------------------------------------------
            // 2b. A stated dose, or an oral dosage form, means pharmaceutical
            //     -- checked here, above the cosmetic rules, so a medicated
            //     product is not thrown out by its form.
            //
            //     "NIZORAL 20MG/ML SHAMPOO" is the case this exists for: an
            //     antifungal that the word SHAMPOO alone would have filed
            //     under Personal Care. "MYREVIT-C PLUS FC TAB" is the other
            //     direction -- ' FC ' reads as "facial cleanser" on every
            //     other product in this catalogue, but not on a tablet.
            //
            //     Vitamins run BEFORE this rule because they carry the same
            //     IU/MG strengths and the same tablet and syrup forms.
            //
            //     Deliberately narrow: only forms that are unambiguously oral
            //     medicine. 'CREAM', 'OINT', 'BALM' and 'SG' stay down in the
            //     main medicine rule, where ice cream, lip balm and softgels
            //     have already been claimed by an earlier rule.
            // ---------------------------------------------------------------
            [self::MEDICINE, [
                self::DOSAGE,
                '/\bTAB(S|LET|LETS)?\b/', '/\bCAPS?(ULE|ULES)?\b/',
                '/\bSYR(UP)?\b/', '/\bSUSP\b/', '/\bAMP(ULE|S)?\b/',
                '/\bVIALS?\b/', '/\bNEB(ULE|ULES)?\b/', '/\bSUPP\b/',
                'LOZENGE', 'DROPS',
            ], []],

            // ---------------------------------------------------------------
            // 3. Baby care. Runs before Personal Care so baby soap, baby
            //    powder and baby cologne do not land in the adult aisle, and
            //    before Milk & Dairy so "J&J BABY SOAP MILK" is not dairy.
            //    Infant formula is deliberately NOT here -- it stays under
            //    Milk & Dairy, where the catalogue already files it.
            // ---------------------------------------------------------------
            [self::BABY_CARE, [
                'BABYFLO', 'BABY FLO', ' BABY ', 'BABY-', 'BABIES', 'BABYRUB',
                'FEEDING BOTTLE', ' F/B ', 'NIPPLE', 'PACIFIER', 'TEETHER',
                'BREAST PUMP', 'NASAL ASPIRATOR', 'BOTTLE BRUSH',
                'DIAPER', 'PAMPERS', 'EQ PANTS', 'EQ DRY', 'MAGIC COLOR',
                'MAGIC DRI', 'MAGIC PANTS', 'HAPPY PANTS',
                'CERELAC', 'NURSY', 'TENDER LOVE', 'UNICARE',
                'J&J POWDER', 'JJ POWDER',
                'PRICKLY HEAT', 'FISSAN', 'KUCHI KUCHI', 'DRAPOLENE',
            ], [
                // Adult incontinence shares the diaper vocabulary but not the
                // aisle.
                'ADULT', 'HYPANTS',
                // Sold in adult, kids and babies strengths; keeping the whole
                // line under Medicine beats splitting it across two aisles.
                'KOOL FEVER',
            ]],

            // ---------------------------------------------------------------
            // 4. Household. Small but real -- these have nowhere sensible to
            //    sit in a pharmacy catalogue otherwise.
            // ---------------------------------------------------------------
            [self::HOUSEHOLD, [
                'DETERGENT', 'DISHWASHING', 'FABRIC CON', 'BLEACH', 'ZONROX',
                'LYSOL', 'BAYGON', 'INSECT KILLER', 'AIR FRESHENER',
                'MOSQUITO COIL', 'TISSUE', 'KLEENEX', 'BATTERY', 'INCENSO',
                'TRASH BAG',
            ], []],

            // ---------------------------------------------------------------
            // 5. Personal care and cosmetics. Deliberately contains no bare
            //    'CREAM' or 'POWDER': those words belong to ice cream, steroid
            //    creams, powdered milk and oral suspensions in roughly equal
            //    measure, so brands and compound forms carry the rule instead.
            // ---------------------------------------------------------------
            [self::PERSONAL_CARE, [
                // forms
                'DEO BS', 'DEO RO', 'DEO SPRAY', 'DEO ROLL', 'DEO STICK',
                'DEODORANT', 'DEO POWDER', ' DEO ', 'ROLL ON',
                'BODY SPRAY', 'BODY MIST', 'COLOGNE', 'PERFUME', 'EAU DE',
                'SOAP', 'SHAMPOO', 'CONDITIONER', ' COND ', 'HAIR GEL',
                'STYLING GEL', 'HAIR WAX', 'HAIR COLOR', 'HAIR BLACKENING',
                'TOOTHPASTE', ' TP ', 'TBRUSH', 'TOOTHBRUSH', 'MOUTHWASH',
                'RAZOR', 'BLADE', 'SHAVING', 'NAIL POLISH', 'LIPSTICK',
                'LIP TINT', 'LIP BALM', 'LIP THERAPY', 'CHAPSTICK',
                'FACE POWDER', 'PRESSED POWDER', 'BODY POWDER',
                'COOLING POWDER', 'FACIAL', 'FACE CREAM', ' FW ', ' FS ',
                ' FF ', ' FC ', 'SUNBLOCK', 'SUNSCREEN', 'PETROLEUM',
                'TAWAS', 'PANTYLINER', ' PL ', 'PLINER', 'NON-WING',
                'W/ WINGS', 'CONDOM', 'LUB JELLY', 'ADULT DIAPER',
                'ADULT UNDERWEAR', 'HYPANTS', 'DENTURE', 'POLIDENT',
                // houses that sell no medicine
                'REXONA', 'NIVEA', ' DOVE', ' AXE', ' BELO', 'SILKA',
                'VASELINE', 'PONDS', "POND'S", 'PALMOLIVE', 'CREAMSILK',
                'SUNSILK', 'COLGATE', 'CLOSE UP', 'SAFEGUARD',
                'HEAD & SHOULDERS', 'JERGENS', 'OLAY', ' BENCH', 'FIONA',
                'HANA ', 'GATSBY', 'GILLETTE', 'BIGEN', 'SENSODYNE',
                'ORAL B', 'HAPEE', 'KOJIESAN', 'SKINWHITE', 'MAXIPEEL',
                'MASTER F', 'RDL ', 'MENA ', 'MESTIZA', 'PLACENTA',
                'DR. WONGS', 'DR. KAUFMANN', 'DR.KAUFMAN', 'CY GABRIEL',
                'LIKAS', 'LS BL', 'TENDER CARE', 'MILCU', 'HAILEYS',
                'OXECURE', 'SOS PIMPLE', 'CETAPHIL', 'GARNIER', 'MYRA ',
                'LEWIS', 'CHIN CHUN SU', 'HIGH ENDURANCE', 'CHARMEE',
                'MODESS', 'SISTERS ', 'WHISPER', 'CAREFREE', 'THOSE DAYS',
                'CLEAR SHAMPOO', 'STYLEX', 'APOLLO LIP', 'SNAKE PRICKLY',
                'KWELL', 'MICELLAR', 'DUREX', 'CLEENE',
            ], []],

            // ---------------------------------------------------------------
            // 6. Ice cream and confectionery. Must beat the medicine rules:
            //    "CREAM CUPS" and "CREAM BARS" are dessert, not dermatology.
            // ---------------------------------------------------------------
            [self::SNACKS, [
                'ICE CREAM', 'CREAM BARS', 'CREAM CONES', 'CREAM CUPS',
                'SUNDAE', 'MINI CUP', 'JUMBO CONES', ' TUB ', 'PINIPIG',
                'PINOY CLASSICS', 'POPSIPOP', 'CREAMLINE', 'GOLD BARS',
                'CANDY', 'LOLLIES', 'LOLLY', 'BISCUIT', 'WAFER', 'CHOCOLATE',
                'FISHERMAN',
            ], [
                'CONDOM',
            ]],

            // ---------------------------------------------------------------
            // 7. Milk, formula and milk-based nutritionals.
            //
            //    Ahead of drinks on purpose: these are named brands, while the
            //    drink rule matches generic words, and "BBRAND ADULT PLUS MILK
            //    & COFFEE" is a tin of milk powder, not a coffee.
            // ---------------------------------------------------------------
            [self::MILK_DAIRY, [
                'POWDERED MILK', 'POWDER MILK', 'FRESH MILK', 'CONDENSED',
                'EVAPORATED', 'CHEESE', 'YOGURT', 'CREAMER', 'VITAMILK',
                'ALASKA', 'BEAR BRAND', 'BEARBRAND', 'BONNA', 'BONAKID',
                'BONAMIL', 'LACTUM', 'ENFAMIL', 'ENFAGROW', 'ENFAMAMA',
                'S26 ', 'SIMILAC', ' NAN ', 'NIDO', 'ANLENE', 'ANMUM',
                'ANCHOR', 'BIRCH TREE', 'BBRAND', 'PEDIASURE', 'ENSURE',
                'GLUCERNA', 'DIABETASOL', 'PROMIL', 'NESTOGEN', 'SUSTAGEN',
                'NUTRIMILK', 'LACTOSE',
            ], []],

            // ---------------------------------------------------------------
            // 8. Drinks. No bare 'WATER' -- that word appears in "HOT WATER
            //    BAG", "WATERGLOSS" hair wax and "STERILE WATER (EUROMED)",
            //    none of which are drinks.
            // ---------------------------------------------------------------
            [self::BEVERAGES, [
                'MINERAL WATER', 'DISTILLED', 'PURIFIED', 'DRINKING WATER',
                'WILKINS', "NATURE'S SPRING", 'NATURES SPRING', 'ABSOLUTE ',
                'JUICE', 'GATORADE', 'POCARI', 'COBRA', 'YAKULT', 'LIPTON',
                'TEABAGS', 'HERBAL TEA', 'COFFEE', 'MILO', 'SOFT DRINK',
                'SODA', 'ENERGY DRINK',
            ], []],

            // ---------------------------------------------------------------
            // 9. Medicine. Mostly a safety net -- anything already filed under
            //    Medicine that matched nothing above simply stays there. What
            //    this rule is really for is pulling actual drugs back OUT of
            //    General Merchandise and Personal Care, where the master file
            //    left contraceptive pills, corticosteroid creams, IV fluids
            //    and inhalers.
            // ---------------------------------------------------------------
            [self::MEDICINE, [
                self::DOSAGE,
                // dosage forms
                '/\bTAB(S|LET|LETS)?\b/', '/\bCAPS?(ULE|ULES)?\b/',
                '/\bSYR(UP)?\b/', '/\bSUSP\b/', '/\bAMP(ULE|S)?\b/',
                '/\bVIALS?\b/', '/\bNEB(ULE|ULES)?\b/', '/\bSUPP\b/',
                '/\bOINT(MENT)?\b/', '/\bCRM\b/', '/\bSG\b/',
                'DROPS', 'INHALER', 'LOZENGE', ' LOZ', ' PILLS', 'CHEWABLE',
                'SOFTGEL', 'PLASTER', 'PATCH', 'THROATSPRAY', 'NASAL SPRAY',
                'ORAL GEL', 'TOPICAL GEL', 'CREAM', 'BALM',
                // fluids and rehydration
                'VIAL', 'UNIT/ML', 'CEFUROXIME',
                'PNSS', 'D5', 'LACTATED RINGERS', 'EUROSOL', 'EUROMED',
                'IRRIGATING SOLN', 'HYDRITE', 'VIVALYTE',
                // brands the master file filed outside Medicine
                'STREPSILS', 'BACTIDOL', 'LOZEMED', 'MUPIROCIN', 'CLOBETASOL',
                'HYDROCORTISONE', 'FLUOCINOLONE', 'BETNO', 'DERMOVATE',
                'ELICA', 'TROSYD', 'NIZORAL', 'KATIALIS', 'KATINKO',
                'EFFICASCENT', 'TIGER BALM', 'VAPORUB', 'SALONPAS',
                'MENTOPAS', 'TERRAMYCIN', 'CALMOSEPTINE', 'BIODERM',
                'FOSKINA', 'CANDIBEC', 'CEBO DE MACHO', 'XYLOGEL', 'FLANAX',
                'GLYDOLAX', 'NASOCLEAR', 'VENTOLIN', 'SALBUTAMOL',
                'MICROPIL', 'MARVELON', 'ALTHEA', 'DAPHNE', 'CHARLIZE',
                'DIANE 35', 'LADY PILLS', 'TRUST PILLS', 'ERIN PILLS',
                'ERMINA', 'STERILE WATER', 'KOOL FEVER', 'SANITARY BALM',
                'MMFRED', 'BETET', 'SAPH', 'DROCORT', 'CHILI PLASTER',
                'TAI CHI', 'KUYO',
            ], [
                // "CREAM CUPS" and friends are already claimed above, but the
                // guard keeps this rule honest if the snack list ever misses
                // a flavour.
                'CREAM BARS', 'CREAM CONES', 'CREAM CUPS', 'ICE CREAM',
            ]],
        ];
    }

    /**
     * The category a product name belongs in, or NULL when no rule is
     * confident enough -- callers leave those products where they are.
     */
    public static function classify(?string $name): ?string
    {
        $name = ' '.strtoupper(preg_replace('/\s+/', ' ', trim((string) $name))).' ';

        if (trim($name) === '') {
            return null;
        }

        foreach (self::rules() as [$category, $patterns, $except]) {
            if (self::matchesAny($name, $except)) {
                continue;
            }

            if (self::matchesAny($name, $patterns)) {
                return $category;
            }
        }

        return null;
    }

    /** True when the (padded, uppercased) name hits any pattern in the list. */
    private static function matchesAny(string $paddedName, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $hit = str_starts_with($pattern, '/')
                ? preg_match($pattern, $paddedName) === 1
                : str_contains($paddedName, $pattern);

            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
