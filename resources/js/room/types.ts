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
};

export type HostRoomProps = {
    event: RoomEvent;
    clusters: Cluster[];
    guests: Guest[];
    nowPlaying: SetItem | null;
    queue: SetItem[];
};
