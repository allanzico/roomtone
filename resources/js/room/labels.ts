import { createRef } from 'react';
import type { RefObject } from 'react';

/**
 * DOM layer that every in-scene label (drei <Html>) renders into. Giving Html a fixed
 * portal target stops it re-mounting when R3F connects its events, which with React 19
 * could leave the first label empty.
 */
export const labelLayer = createRef<HTMLDivElement>();

/** drei types the portal as non-null; it is only read after mount, once the layer exists. */
export const labelPortal = labelLayer as RefObject<HTMLElement>;
