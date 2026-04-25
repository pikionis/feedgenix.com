import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AxiosError } from 'axios';
import { useMappingStore, type FieldMapping } from './mappingStore';
import api from '@/api/client';

function fakeMapping(overrides: Partial<FieldMapping> = {}): FieldMapping {
  return {
    id: 1,
    project_id: 42,
    upload_id: 7,
    mapping: {
      title: { kind: 'translatable', confidence: 0.92, source_column: 'title' },
      price: { kind: 'protected', confidence: 1.0, source_column: 'price' },
      size: { kind: 'code', confidence: 0.88, source_column: 'size' },
    },
    translatable_fields: ['title'],
    protected_fields: ['price'],
    skipped_fields: ['size'],
    ambiguous_fields: [],
    detected_source_language: 'en',
    ai_confidence: 0.9,
    ai_model: 'claude-opus-4-7',
    user_approved: false,
    approved_at: null,
    ...overrides,
  };
}

function reset() {
  useMappingStore.setState({
    mapping: null,
    isLoading: false,
    isSaving: false,
    error: null,
  });
}

describe('mappingStore', () => {
  beforeEach(() => {
    reset();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    reset();
  });

  it('fetches the mapping and stores it on success', async () => {
    const m = fakeMapping();
    vi.spyOn(api, 'get').mockResolvedValue({ data: m });

    await useMappingStore.getState().fetch(42);

    expect(useMappingStore.getState().mapping).toEqual(m);
    expect(useMappingStore.getState().isLoading).toBe(false);
    expect(useMappingStore.getState().error).toBeNull();
  });

  it('surfaces a "not ready" error when the mapping endpoint 404s', async () => {
    const err = new AxiosError('Not Found', '404');
    err.response = { status: 404, data: { message: 'No mapping found for this project. Upload a file first.' } } as never;
    vi.spyOn(api, 'get').mockRejectedValue(err);

    await useMappingStore.getState().fetch(42);

    expect(useMappingStore.getState().mapping).toBeNull();
    expect(useMappingStore.getState().error).toBe('No mapping found for this project. Upload a file first.');
  });

  it('save() PUTs the buckets and updates the local mapping with the response', async () => {
    const updated = fakeMapping({ translatable_fields: ['title', 'description'] });
    const put = vi.spyOn(api, 'put').mockResolvedValue({ data: updated });

    await useMappingStore.getState().save(42, {
      translatable_fields: ['title', 'description'],
      protected_fields: ['price'],
      skipped_fields: ['size'],
    });

    expect(put).toHaveBeenCalledWith('/api/projects/42/mapping', {
      translatable_fields: ['title', 'description'],
      protected_fields: ['price'],
      skipped_fields: ['size'],
    });
    expect(useMappingStore.getState().mapping?.translatable_fields).toEqual(['title', 'description']);
  });

  it('save() surfaces server validation errors and rethrows so the caller can short-circuit', async () => {
    const err = new AxiosError('Unprocessable', '422');
    err.response = { status: 422, data: { errors: { translatable_fields: ['Cannot be empty.'] } } } as never;
    vi.spyOn(api, 'put').mockRejectedValue(err);

    await expect(
      useMappingStore.getState().save(42, {
        translatable_fields: [],
        protected_fields: ['price'],
        skipped_fields: [],
      }),
    ).rejects.toBeDefined();

    expect(useMappingStore.getState().error).toBe('Cannot be empty.');
    expect(useMappingStore.getState().isSaving).toBe(false);
  });

  it('approve() sends user_approved=true with the current bucket state', async () => {
    useMappingStore.setState({ mapping: fakeMapping() });
    const approved = fakeMapping({ user_approved: true, approved_at: '2026-04-23T10:00:00Z' });
    const put = vi.spyOn(api, 'put').mockResolvedValue({ data: approved });

    await useMappingStore.getState().approve(42);

    expect(put).toHaveBeenCalledWith('/api/projects/42/mapping', {
      translatable_fields: ['title'],
      protected_fields: ['price'],
      skipped_fields: ['size'],
      user_approved: true,
    });
    expect(useMappingStore.getState().mapping?.user_approved).toBe(true);
  });

  it('approve() is a no-op when there is no mapping loaded', async () => {
    const put = vi.spyOn(api, 'put').mockResolvedValue({ data: {} });

    await useMappingStore.getState().approve(42);

    expect(put).not.toHaveBeenCalled();
  });

  it('reset() clears mapping + flags + error', () => {
    useMappingStore.setState({ mapping: fakeMapping(), isSaving: true, error: 'kapow' });
    useMappingStore.getState().reset();
    const s = useMappingStore.getState();
    expect(s.mapping).toBeNull();
    expect(s.isSaving).toBe(false);
    expect(s.error).toBeNull();
  });
});
