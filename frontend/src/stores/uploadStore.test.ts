import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { AxiosError } from 'axios';
import { useUploadStore } from './uploadStore';
import api from '@/api/client';

/**
 * The store's job is to call api.post correctly, surface progress, and
 * normalise every flavour of error so callers don't have to. We mock the
 * axios client and exercise both the happy path and three failure modes
 * the user actually hits in the wild: validation 422, network error, and
 * a generic non-axios throw.
 */

function file(): File {
  return new File(['sku,title\nA-1,x\n'], 'products.csv', { type: 'text/csv' });
}

function resetStore() {
  useUploadStore.setState({
    isUploading: false,
    progress: 0,
    error: null,
  });
}

describe('uploadStore', () => {
  beforeEach(() => {
    resetStore();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    resetStore();
  });

  it('posts the file as multipart/form-data to the project upload endpoint', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { id: 1 } });

    await useUploadStore.getState().upload(42, file());

    expect(post).toHaveBeenCalledOnce();
    const [url, body, opts] = post.mock.calls[0];
    expect(url).toBe('/api/projects/42/upload');
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get('file')).toBeInstanceOf(File);
    expect((opts as { headers: Record<string, string> }).headers['Content-Type']).toBe('multipart/form-data');
  });

  it('emits progress 0 → final and clears error on success', async () => {
    vi.spyOn(api, 'post').mockResolvedValue({ data: {} });

    await useUploadStore.getState().upload(1, file());

    const s = useUploadStore.getState();
    expect(s.isUploading).toBe(false);
    expect(s.progress).toBe(100);
    expect(s.error).toBeNull();
  });

  it('updates progress while the upload is mid-flight via onUploadProgress', async () => {
    let onProgress: ((e: { total?: number; loaded: number }) => void) | undefined;
    vi.spyOn(api, 'post').mockImplementation((_url, _body, opts) => {
      onProgress = (opts as { onUploadProgress?: typeof onProgress }).onUploadProgress;
      return new Promise((resolve) => {
        setTimeout(() => resolve({ data: {} } as any), 5);
      });
    });

    const promise = useUploadStore.getState().upload(1, file());
    // Simulate axios firing the progress callback halfway.
    onProgress?.({ total: 100, loaded: 50 });
    expect(useUploadStore.getState().progress).toBe(50);

    await promise;
    expect(useUploadStore.getState().progress).toBe(100);
  });

  it('surfaces the first Laravel validation error message', async () => {
    const err = new AxiosError('Request failed', '422');
    err.response = {
      status: 422,
      data: {
        message: 'The given data was invalid.',
        errors: { file: ['The file must be a CSV.'] },
      },
    } as never;
    vi.spyOn(api, 'post').mockRejectedValue(err);

    await expect(useUploadStore.getState().upload(1, file())).rejects.toBeDefined();

    const s = useUploadStore.getState();
    expect(s.isUploading).toBe(false);
    expect(s.progress).toBe(0);
    expect(s.error).toBe('The file must be a CSV.');
  });

  it('falls back to response.data.message when there are no field errors', async () => {
    const err = new AxiosError('Forbidden', '403');
    err.response = {
      status: 403,
      data: { message: 'Your plan does not allow uploads.' },
    } as never;
    vi.spyOn(api, 'post').mockRejectedValue(err);

    await expect(useUploadStore.getState().upload(1, file())).rejects.toBeDefined();

    expect(useUploadStore.getState().error).toBe('Your plan does not allow uploads.');
  });

  it('uses the generic message for a network error with no response', async () => {
    const err = new AxiosError('Network Error');
    // No response — simulates a failed connection.
    vi.spyOn(api, 'post').mockRejectedValue(err);

    await expect(useUploadStore.getState().upload(1, file())).rejects.toBeDefined();

    expect(useUploadStore.getState().error).toBe('Upload failed. Please try again.');
  });

  it('uses the generic message for a non-Axios throw', async () => {
    vi.spyOn(api, 'post').mockRejectedValue(new Error('boom'));

    await expect(useUploadStore.getState().upload(1, file())).rejects.toBeDefined();

    expect(useUploadStore.getState().error).toBe('Upload failed. Please try again.');
  });

  it('reset() clears error, progress, and isUploading flags', () => {
    useUploadStore.setState({
      isUploading: true,
      progress: 47,
      error: 'kapow',
    });

    useUploadStore.getState().reset();

    const s = useUploadStore.getState();
    expect(s.isUploading).toBe(false);
    expect(s.progress).toBe(0);
    expect(s.error).toBeNull();
  });
});
