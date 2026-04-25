import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import UploadDropzone from './UploadDropzone';

/**
 * Test infrastructure note: react-dropzone uses the File API + drag events.
 * jsdom supports File well enough for click-uploads (input change), but
 * synthesizing a real DataTransfer with files is brittle. We exercise the
 * click→input-change path because it's the canonical user flow on desktop;
 * drag-drop fires the same callbacks once the files reach onDrop.
 */

function makeCsvFile(name = 'products.csv', sizeBytes = 200): File {
  const content = 'a'.repeat(sizeBytes);
  return new File([content], name, { type: 'text/csv' });
}

function getFileInput(): HTMLInputElement {
  // react-dropzone renders an `<input type="file">` we can address by label.
  return screen.getByLabelText('CSV file') as HTMLInputElement;
}

describe('UploadDropzone', () => {
  it('renders the idle CTA with size info', () => {
    render(<UploadDropzone onFile={vi.fn()} />);
    expect(screen.getByText(/Drag a CSV here/)).toBeInTheDocument();
    expect(screen.getByText(/up to 50 MB/)).toBeInTheDocument();
  });

  it('respects a custom maxBytes in the idle copy', () => {
    render(<UploadDropzone onFile={vi.fn()} maxBytes={5 * 1024 * 1024} />);
    expect(screen.getByText(/up to 5 MB/)).toBeInTheDocument();
  });

  it('calls onFile with the chosen csv file', async () => {
    const user = userEvent.setup();
    const onFile = vi.fn();
    render(<UploadDropzone onFile={onFile} />);

    const file = makeCsvFile('products.csv', 1024);
    await user.upload(getFileInput(), file);

    expect(onFile).toHaveBeenCalledOnce();
    expect(onFile).toHaveBeenCalledWith(file);
  });

  it('rejects a non-csv file with an inline message and never calls onFile', async () => {
    const user = userEvent.setup();
    const onFile = vi.fn();
    render(<UploadDropzone onFile={onFile} />);

    const png = new File([new Uint8Array([0x89, 0x50])], 'photo.png', { type: 'image/png' });
    await user.upload(getFileInput(), png);

    expect(onFile).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toHaveTextContent('Only CSV or TSV files are accepted.');
  });

  it('rejects a file above the size cap with an inline message', async () => {
    const user = userEvent.setup();
    const onFile = vi.fn();
    render(<UploadDropzone onFile={onFile} maxBytes={1024} />);

    const big = makeCsvFile('huge.csv', 4096);   // 4KB > 1KB cap
    await user.upload(getFileInput(), big);

    expect(onFile).not.toHaveBeenCalled();
    expect(screen.getByRole('alert')).toHaveTextContent(/larger than 0 MB/);
  });

  it('shows the progress bar instead of the CTA while uploading', () => {
    render(
      <UploadDropzone onFile={vi.fn()} uploading progress={42} />
    );
    expect(screen.queryByText(/Drag a CSV here/)).not.toBeInTheDocument();
    const bar = screen.getByRole('progressbar');
    expect(bar).toHaveAttribute('aria-valuenow', '42');
    expect(screen.getByText('42%')).toBeInTheDocument();
  });

  it('clamps progress values into [0, 100]', () => {
    render(<UploadDropzone onFile={vi.fn()} uploading progress={250} />);
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
  });

  it('renders the external error prop verbatim', () => {
    render(<UploadDropzone onFile={vi.fn()} error="Plan does not allow files this large." />);
    expect(screen.getByRole('alert')).toHaveTextContent('Plan does not allow files this large.');
  });

  it('disables the dropzone while uploading', () => {
    render(<UploadDropzone onFile={vi.fn()} uploading progress={10} />);
    const root = screen.getByRole('button', { name: 'Upload CSV file' });
    expect(root).toHaveAttribute('aria-disabled', 'true');
    expect(root).toHaveAttribute('data-state', 'uploading');
  });

  it('disables the dropzone when disabled prop is true (e.g. wrong project status)', () => {
    render(<UploadDropzone onFile={vi.fn()} disabled />);
    const root = screen.getByRole('button', { name: 'Upload CSV file' });
    expect(root).toHaveAttribute('aria-disabled', 'true');
  });

  it('clears the local rejection message when a subsequent valid file is chosen', async () => {
    const user = userEvent.setup();
    const onFile = vi.fn();
    render(<UploadDropzone onFile={onFile} />);

    const png = new File(['x'], 'photo.png', { type: 'image/png' });
    await user.upload(getFileInput(), png);
    expect(screen.getByRole('alert')).toBeInTheDocument();

    await user.upload(getFileInput(), makeCsvFile('ok.csv', 100));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(onFile).toHaveBeenCalled();
  });
});
