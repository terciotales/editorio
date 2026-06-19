import {config} from '../config';

export const publisherEndpoint = (path = '') => `${config.restNamespace}/publisher${path}`;
export const sourcesEndpoint = (path = '') => `${config.restNamespace}/sources${path}`;
export const aiEndpoint = (path = '') => `${config.restNamespace}/ai${path}`;
