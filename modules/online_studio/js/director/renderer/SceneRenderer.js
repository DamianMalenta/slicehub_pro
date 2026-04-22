/**
 * Thin compatibility wrapper.
 *
 * Full scene rendering now lives in `core/js/surface/SharedSceneRenderer.js`.
 * Director keeps the old import path to avoid touching every consumer.
 */
export { SharedSceneRenderer as SceneRenderer } from '../../../../../core/js/surface/SharedSceneRenderer.js';
