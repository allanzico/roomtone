import type { Cluster, Guest } from './types';

const NAMES = [
    'Zara',
    'Mateus',
    'Noor',
    'Diego',
    'Yuki',
    'Femi',
    'Sofia',
    'Luca',
    'Amara',
    'Rui',
    'Chiara',
    'Kwame',
    'Mei',
    'Olu',
    'Beatriz',
    'Sami',
];
const COLORS = [
    '#FFB86B',
    '#7DF9FF',
    '#FF7EB6',
    '#C3F584',
    '#A78BFA',
    '#FFE66D',
    '#5CE1E6',
    '#FF8F8F',
];
const LOVES = [
    'Succession',
    'Lisbon trams',
    'ramen',
    'Rosalía',
    'Fleabag',
    'Accra',
    'piri-piri chicken',
    'Daft Punk',
    'Kyoto',
    'The Bear',
    'Fela Kuti',
    'mango sticky rice',
];

let count = 0;

/** Dev control (G): invent a guest who joins the room live. Ids are negative so they never clash with real ones. */
export function makeFakeGuest(clusters: Cluster[]): Guest {
    count += 1;
    const [main, other] = sample(clusters, 2);

    return {
        id: -count,
        name: NAMES[(count - 1) % NAMES.length],
        color: COLORS[(count - 1) % COLORS.length],
        loves: sample(LOVES, 3),
        clusterWeights: [
            ...(main ? [{ clusterId: main.id, weight: 0.75 }] : []),
            ...(other ? [{ clusterId: other.id, weight: 0.25 }] : []),
        ],
        arrivedLive: true,
    };
}

function sample<T>(list: T[], n: number): T[] {
    return [...list].sort(() => Math.random() - 0.5).slice(0, n);
}
