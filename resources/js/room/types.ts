export type Vec3 = [number, number, number];

export type RoomEvent = {
    code: string;
    name: string;
    occasion: string | null;
    energy: number;
};

export type Cluster = {
    id: number;
    label: string;
    color: string;
    position: Vec3;
};

export type ClusterWeight = {
    clusterId: number;
    weight: number;
};

export type Guest = {
    id: number;
    name: string;
    color: string;
    loves: string[];
    clusterWeights: ClusterWeight[];
    /** True when the guest joined while the room was open, so their orb flies in. */
    arrivedLive?: boolean;
};

export type SetItem = {
    id: number;
    title: string;
    artist: string;
    /** YouTube video id, or null when no playable video was found; the player skips those. */
    videoId: string | null;
    energy: number;
    reasonGuestId: number | null;
    reasonText: string;
    /** The island of the track's own genre (its artist's Qloo tags), or null when it matches none. */
    clusterId: number | null;
};

export type HostRequestState = {
    id: number;
    text: string;
    status: 'pending' | 'done' | 'no_change' | 'failed';
    /** The DJ's one-line answer, once it has one. */
    reply: string | null;
};

export type JoinInfo = {
    /** The join page guests open by scanning the QR. */
    url: string;
    host: string;
    /** SVG markup of the QR code, drawn by the server. */
    qr: string;
};

export type HostRoomProps = {
    /** The host ended the party: the screen stops and says so. */
    ended: boolean;
    event: RoomEvent;
    join: JoinInfo;
    clusters: Cluster[];
    guests: Guest[];
    nowPlaying: SetItem | null;
    queue: SetItem[];
    /** Present only when the page was opened with the host link: unlocks requests and playback sync. */
    hostKey: string | null;
    latestRequest: HostRequestState | null;
};
