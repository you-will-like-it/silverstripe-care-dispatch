<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Dev;

/**
 * Fictional demo data: an ambulant care service in Berlin (Prenzlauer Berg, Pankow, Weißensee, Mitte).
 *
 * All persons are invented. Street names are real, house numbers and coordinates are
 * synthetic (generated around the district centres). Service codes are example codes,
 * not an official Leistungskatalog of any Bundesland.
 */
final class DemoDataset
{
    public const EMAIL_DOMAIN = 'demo.care-dispatch.test';

    /** Office of the fictional care service = start and end of routes. */
    public const DEPOT = [
        'company' => 'Pflegedienst Sonnenschein (Demo)',
        'street' => 'Schönhauser Allee 80',
        'postalCode' => '10439',
        'city' => 'Berlin',
        'lat' => 52.5386,
        'lng' => 13.4125,
    ];

    /** Default time windows per slot. */
    public const SLOT_WINDOWS = [
        'Frueh' => ['06:00', '11:00'],
        'Mittag' => ['11:30', '13:30'],
        'Spaet' => ['16:30', '21:00'],
        'Nacht' => ['21:00', '23:30'],
    ];

    public const PDL = ['first' => 'Sabine', 'last' => 'Krüger', 'email' => 'pdl'];

    public const OFFICE = ['first' => 'Thomas', 'last' => 'Weber', 'email' => 'verwaltung'];

    /**
     * key => [first, last, qualification, weekly hours, driving licence, colour, shifts [[type, start, end]], own start [lat, lng]|null]
     */
    public const CAREGIVERS = [
        'anna' => ['Anna', 'Becker', 'Fachkraft', 39.0, true, '#2563eb', [['Frueh', '06:00', '14:00']], null],
        'mehmed' => ['Mehmed', 'Hodžić', 'Fachkraft', 39.0, true, '#7c3aed', [['Frueh', '06:00', '14:00']], [52.5716, 13.4098]],
        'laura' => ['Laura', 'Schneider', 'Fachkraft', 30.0, true, '#db2777', [['Spaet', '13:30', '21:30']], null],
        'jonas' => ['Jonas', 'Wagner', 'PflegehelferLG1', 39.0, true, '#059669', [['Frueh', '06:30', '14:30']], null],
        'selma' => ['Selma', 'Kovačević', 'PflegehelferLG1', 25.0, true, '#d97706', [['Teildienst', '06:30', '10:30'], ['Teildienst', '16:00', '20:30']], null],
        'peter' => ['Peter', 'Hoffmann', 'Hilfskraft', 39.0, false, '#0891b2', [['Spaet', '13:00', '21:00']], null],
        'mia' => ['Mia', 'Fischer', 'Hilfskraft', 20.0, true, '#65a30d', [['Frueh', '07:00', '13:00']], null],
        'lukas' => ['Lukas', 'Braun', 'Azubi', 39.0, true, '#64748b', [['Frueh', '06:00', '14:00']], null],
    ];

    /** code => [title, category, min qualification, minutes] */
    public const TASKS = [
        'LK 01' => ['Kleine Morgen-/Abendtoilette', 'SGBXI', 'Hilfskraft', 20],
        'LK 02' => ['Große Morgen-/Abendtoilette', 'SGBXI', 'Hilfskraft', 35],
        'LK 03' => ['Hilfe beim Aufstehen/Zubettgehen', 'SGBXI', 'Hilfskraft', 10],
        'LK 04' => ['Lagern und Mobilisation', 'SGBXI', 'Hilfskraft', 10],
        'LK 05' => ['Hilfe bei der Nahrungsaufnahme', 'SGBXI', 'Hilfskraft', 15],
        'LK 06' => ['Duschen / Baden', 'SGBXI', 'Hilfskraft', 30],
        'LK 07' => ['Hauswirtschaftliche Versorgung', 'SGBXI', 'Hilfskraft', 30],
        'LK 08' => ['Transfer mit Lifter (2 Personen)', 'SGBXI', 'Hilfskraft', 15],
        'HKP 01' => ['Medikamentengabe (oral)', 'SGBV', 'PflegehelferLG1', 5],
        'HKP 02' => ['Medikamente richten (Wochendispenser)', 'SGBV', 'Fachkraft', 15],
        'HKP 03' => ['Blutzuckermessung', 'SGBV', 'PflegehelferLG1', 5],
        'HKP 04' => ['Insulininjektion s.c.', 'SGBV', 'Fachkraft', 10],
        'HKP 05' => ['Kompressionsstrümpfe an-/ausziehen', 'SGBV', 'PflegehelferLG1', 10],
        'HKP 06' => ['Verbandwechsel / Wundversorgung', 'SGBV', 'Fachkraft', 20],
        'HKP 07' => ['Blutdruckmessung', 'SGBV', 'PflegehelferLG1', 5],
    ];

    /** district => [lat, lng, [[street, postcode], ...]] */
    public const DISTRICTS = [
        'prenzlberg' => [52.5390, 13.4180, [['Kastanienallee', '10435'], ['Lychener Straße', '10437'], ['Dunckerstraße', '10437'], ['Greifswalder Straße', '10405'], ['Pappelallee', '10437']]],
        'pankow' => [52.5690, 13.4020, [['Florastraße', '13187'], ['Breite Straße', '13187'], ['Mühlenstraße', '13187'], ['Wollankstraße', '13187'], ['Berliner Straße', '13189']]],
        'weissensee' => [52.5530, 13.4650, [['Berliner Allee', '13088'], ['Langhansstraße', '13086'], ['Pistoriusstraße', '13086']]],
        'mitte' => [52.5300, 13.3930, [['Ackerstraße', '10115'], ['Brunnenstraße', '10119'], ['Torstraße', '10119']]],
        'gesundbrunnen' => [52.5480, 13.3880, [['Badstraße', '13357'], ['Prinzenallee', '13359']]],
    ];

    /**
     * key => [first, last, district, street index, phone, access notes, care notes, care plan, options]
     *
     * care plan entry: [task code, slot, weekdays CSV|null (= daily), instruction|null, staff, window [from, to]|null]
     * options: preferred => [caregiver keys], excluded => [caregiver keys], noGeo => true
     */
    public const PATIENTS = [
        'p01' => ['Erika', 'Lehmann', 'prenzlberg', 0, '030 4401 1101', 'Schlüsselsafe neben der Tür, Code liegt im Büro.', 'Diabetes Typ 1, BZ-Werte im Heft dokumentieren.', [
            ['LK 02', 'Frueh', null, null, 1, null],
            ['HKP 03', 'Frueh', null, 'Vor dem Frühstück messen', 1, null],
            ['HKP 04', 'Frueh', null, 'Insulin glargin 12 IE s.c.', 1, null],
            ['HKP 04', 'Spaet', null, 'Insulin normal nach Korrekturschema', 1, null],
        ], ['preferred' => ['anna']]],
        'p02' => ['Helmut', 'Schulz', 'prenzlberg', 1, '030 4401 1102', 'Klingel "Schulz/Hartwig", 3. OG ohne Aufzug.', 'Ödeme an beiden Unterschenkeln.', [
            ['LK 01', 'Frueh', null, null, 1, null],
            ['HKP 05', 'Frueh', null, 'Kompressionsklasse II, anziehen', 1, null],
            ['LK 06', 'Frueh', '2,5', 'Duschen dienstags und freitags', 1, null],
            ['HKP 05', 'Spaet', null, 'Strümpfe ausziehen, Haut kontrollieren', 1, null],
        ], []],
        'p03' => ['Ingrid', 'Wolff', 'prenzlberg', 2, '030 4401 1103', 'Tochter wohnt im EG und öffnet.', 'Leichte Demenz, Tagesstruktur wichtig.', [
            ['LK 01', 'Frueh', null, null, 1, null],
            ['HKP 01', 'Frueh', null, 'Ramipril 5 mg 1-0-0, ASS 100 mg 1-0-0', 1, null],
            ['HKP 07', 'Frueh', '1,4', 'RR montags und donnerstags', 1, null],
            ['LK 05', 'Mittag', null, 'Mittagessen anreichen, Trinkmenge notieren', 1, null],
        ], []],
        'p04' => ['Gerda', 'Neumann', 'prenzlberg', 3, '030 4401 1104', 'Lifter steht im Schlafzimmer.', 'Immobil, Dekubitusprophylaxe, Transfer nur zu zweit.', [
            ['LK 02', 'Frueh', null, null, 1, null],
            ['LK 08', 'Frueh', null, 'Transfer Bett → Rollstuhl', 2, null],
            ['LK 04', 'Frueh', null, 'Lagerung 30°, Fersen frei', 1, null],
            ['LK 08', 'Spaet', null, 'Transfer Rollstuhl → Bett', 2, null],
            ['LK 04', 'Spaet', null, null, 1, null],
        ], []],
        'p05' => ['Werner', 'Krause', 'prenzlberg', 4, '030 4401 1105', null, 'Ulcus cruris links.', [
            ['LK 01', 'Frueh', null, null, 1, null],
            ['HKP 01', 'Frueh', null, 'Torasemid 10 mg 1-0-0', 1, null],
            ['HKP 06', 'Frueh', '1,3,5', 'Ulcus cruris li.: Wundreinigung, Silberverband, Fotodoku freitags', 1, null],
        ], []],
        'p06' => ['Ursula', 'Zimmermann', 'pankow', 0, '030 4401 1106', 'Hund im Flur, freundlich.', null, [
            ['LK 02', 'Frueh', null, null, 1, null],
            ['HKP 02', 'Frueh', '1', 'Wochendispenser montags richten', 1, null],
            ['HKP 01', 'Frueh', null, 'Medikamente laut Medikationsplan', 1, null],
        ], []],
        'p07' => ['Hildegard', 'Hartmann', 'pankow', 1, '030 4401 1107', null, 'Diabetes Typ 2, insulinpflichtig.', [
            ['LK 01', 'Frueh', null, null, 1, null],
            ['HKP 03', 'Frueh', null, null, 1, null],
            ['HKP 04', 'Frueh', null, 'Mischinsulin 16 IE vor dem Frühstück', 1, null],
            ['HKP 04', 'Spaet', null, 'Mischinsulin 10 IE vor dem Abendessen', 1, null],
        ], ['excluded' => ['peter']]],
        'p08' => ['Günter', 'Schmitt', 'pankow', 2, '030 4401 1108', null, null, [
            ['LK 02', 'Frueh', null, null, 1, null],
            ['LK 07', 'Frueh', '3', 'Einkauf und Wäsche mittwochs', 1, null],
            ['LK 05', 'Mittag', null, null, 1, null],
        ], []],
        'p09' => ['Elfriede', 'Meier', 'pankow', 3, '030 4401 1109', 'Schlüssel beim Nachbarn (Hr. Polat, 1. OG).', null, [
            ['LK 01', 'Frueh', null, null, 1, null],
            ['HKP 05', 'Frueh', null, 'Anziehen', 1, null],
            ['HKP 05', 'Spaet', null, 'Ausziehen', 1, null],
        ], ['excluded' => ['jonas']]],
        'p10' => ['Rudolf', 'Lange', 'pankow', 4, '030 4401 1110', null, 'PEG-Sonde.', [
            ['LK 01', 'Frueh', null, null, 1, null],
            ['HKP 06', 'Frueh', null, 'PEG-Verbandwechsel, Einstichstelle kontrollieren', 1, null],
            ['HKP 01', 'Frueh', null, 'Medikamente gemörsert über PEG', 1, null],
        ], []],
        'p11' => ['Margarete', 'Richter', 'weissensee', 0, '030 4401 1111', null, null, [
            ['LK 02', 'Frueh', null, null, 1, null],
            ['HKP 01', 'Frueh', null, 'Bisoprolol 2,5 mg 1-0-0', 1, null],
            ['HKP 07', 'Frueh', null, null, 1, null],
        ], []],
        'p12' => ['Karl-Heinz', 'Schröder', 'weissensee', 1, '030 4401 1112', null, null, [
            ['LK 01', 'Frueh', null, null, 1, null],
            ['HKP 05', 'Frueh', null, 'Anziehen', 1, null],
            ['HKP 05', 'Spaet', null, 'Ausziehen', 1, null],
        ], []],
        'p13' => ['Dragica', 'Marić', 'weissensee', 2, '030 4401 1113', 'Spricht wenig Deutsch, Sohn ist Ansprechpartner.', 'Diabetes Typ 2, orale Therapie.', [
            ['LK 02', 'Frueh', null, null, 1, null],
            ['HKP 03', 'Frueh', null, null, 1, null],
            ['HKP 01', 'Frueh', null, 'Metformin 500 mg 1-0-1', 1, null],
            ['HKP 01', 'Spaet', null, 'Metformin 500 mg 1-0-1 (abends)', 1, null],
        ], []],
        'p14' => ['Brigitte', 'Koch', 'weissensee', 0, '030 4401 1114', null, null, [
            ['LK 01', 'Frueh', null, null, 1, null],
            ['LK 03', 'Spaet', null, 'Zubettgehen', 1, null],
        ], []],
        // Not on any tour template -> always in the pool.
        'p15' => ['Friedrich', 'Vogel', 'mitte', 0, '030 4401 1115', null, 'Insulinpflichtig, neu seit dieser Woche.', [
            ['HKP 04', 'Mittag', null, 'Insulin normal 6 IE vor dem Mittagessen', 1, ['11:30', '12:30']],
        ], []],
        'p16' => ['Johanna', 'Berger', 'mitte', 1, '030 4401 1116', 'Neuaufnahme, Erstbesuch mit Pflegeanamnese.', null, [
            ['LK 02', 'Frueh', null, null, 1, ['08:00', '10:00']],
        ], []],
        'p17' => ['Senad', 'Hadžić', 'gesundbrunnen', 0, '030 4401 1117', null, null, [
            ['LK 01', 'Frueh', null, null, 1, ['08:30', '10:30']],
            ['HKP 01', 'Frueh', null, 'Amlodipin 5 mg 1-0-0', 1, ['08:30', '10:30']],
        ], []],
        'p18' => ['Waltraud', 'Simon', 'gesundbrunnen', 1, '030 4401 1118', 'Adresse wird noch geprüft (Hinterhaus?).', null, [
            ['LK 07', 'Mittag', null, 'Wohnung reinigen, Einkauf', 1, null],
        ], ['noGeo' => true]],
    ];

    /**
     * key => [title, shift type, default caregiver key|null, first start HH:MM, [[patient key, slot], ...]]
     */
    public const TOURS = [
        't1' => ['Tour Prenzlauer Berg – Früh', 'Frueh', 'anna', '06:30', [['p01', 'Frueh'], ['p02', 'Frueh'], ['p03', 'Frueh'], ['p04', 'Frueh'], ['p05', 'Frueh']]],
        't2' => ['Tour Pankow – Früh', 'Frueh', 'mehmed', '06:30', [['p07', 'Frueh'], ['p06', 'Frueh'], ['p08', 'Frueh'], ['p09', 'Frueh'], ['p10', 'Frueh']]],
        't3' => ['Tour Weißensee – Früh', 'Frueh', 'jonas', '07:00', [['p11', 'Frueh'], ['p12', 'Frueh'], ['p13', 'Frueh'], ['p14', 'Frueh']]],
        't4' => ['Tour Prenzlauer Berg/Pankow – Spät', 'Spaet', 'laura', '17:00', [['p01', 'Spaet'], ['p02', 'Spaet'], ['p04', 'Spaet'], ['p07', 'Spaet'], ['p09', 'Spaet']]],
        // No Bezugspflegekraft: shows up as "unbesetzte Tour" on the board.
        't5' => ['Tour Weißensee – Spät', 'Spaet', null, '17:30', [['p12', 'Spaet'], ['p13', 'Spaet'], ['p14', 'Spaet']]],
    ];

    /** Caregiver used to staff the unstaffed template tour on past days (must satisfy all hard rules). */
    public const PAST_STAFF_FOR_UNSTAFFED = ['t5' => 'selma'];

    /** Minutes of travel planned between two stops when computing template times. */
    public const TEMPLATE_TRAVEL_MINUTES = 10;
}
