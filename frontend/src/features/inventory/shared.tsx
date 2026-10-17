import { useRef, useState } from 'react';
import { Box, Chip, IconButton, LinearProgress, Stack, Tooltip, Typography } from '@mui/material';
import ApartmentOutlinedIcon from '@mui/icons-material/ApartmentOutlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import PictureAsPdfOutlinedIcon from '@mui/icons-material/PictureAsPdfOutlined';
import StarRoundedIcon from '@mui/icons-material/StarRounded';
import StarBorderRoundedIcon from '@mui/icons-material/StarBorderRounded';
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { fileUrl, filesApi, projectsApi, type FileItem, type Property, type PropertyStatus, type PropertyType } from '@/api/inventory';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';

export const STATUS_LABEL: Record<PropertyStatus, string> = { available: 'Available', under_offer: 'Under offer', reserved: 'Reserved', sold: 'Sold', rented: 'Rented', inactive: 'Inactive' };
const STATUS_COLOR: Record<PropertyStatus, 'success' | 'warning' | 'default' | 'primary'> = { available: 'success', under_offer: 'warning', reserved: 'warning', sold: 'primary', rented: 'primary', inactive: 'default' };
export const TYPE_LABEL: Record<PropertyType, string> = { apartment: 'Apartment', villa: 'Villa', townhouse: 'Townhouse', penthouse: 'Penthouse', office: 'Office', retail: 'Retail', plot: 'Plot', other: 'Other' };
export const FURNISHING_LABEL = { unfurnished: 'Unfurnished', semi_furnished: 'Semi-furnished', furnished: 'Furnished' } as const;

export function StatusChip({ status, size = 'small' }: { status: PropertyStatus; size?: 'small' | 'medium' }) {
  return <Chip size={size} label={STATUS_LABEL[status]} color={STATUS_COLOR[status]} variant={status === 'available' || status === 'inactive' ? 'outlined' : 'filled'} sx={status === 'available' || status === 'inactive' ? { bgcolor: 'background.paper' } : undefined} />;
}

export const formatArea = (v: number | null) => (v ? `${Math.round(v).toLocaleString('en-IN')} sq ft` : null);

/** “3 bd · 2 ba · 1,650 sq ft” — only what is known. */
export function specsLine(p: Pick<Property, 'bedrooms' | 'bathrooms' | 'areaSqft' | 'propertyType'>): string {
  return [p.bedrooms ? `${p.bedrooms} bd` : null, p.bathrooms ? `${p.bathrooms} ba` : null, formatArea(p.areaSqft)].filter(Boolean).join(' · ') || TYPE_LABEL[p.propertyType];
}

/** Cover picture with a calm placeholder; images are served by the authenticated files endpoint (same-origin cookie). */
export function PropertyImage({ fileId, alt, ratio = '4 / 3', radius = 2 }: { fileId: string | null; alt: string; ratio?: string; radius?: number }) {
  const [broken, setBroken] = useState(false);
  return (
    <Box sx={{ aspectRatio: ratio, borderRadius: radius, overflow: 'hidden', bgcolor: 'surface.sunken', display: 'grid', placeItems: 'center', color: 'text.disabled' }}>
      {fileId && !broken ? <Box component="img" src={fileUrl(fileId)} alt={alt} loading="lazy" onError={() => setBroken(true)} sx={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} /> : <ApartmentOutlinedIcon sx={{ fontSize: 40 }} aria-hidden />}
    </Box>
  );
}

export function useProjectOptions() {
  return useQuery({ queryKey: ['projects', 'options'], queryFn: async () => (await projectsApi.list({ sort: 'name' })).data, staleTime: 60_000 });
}

const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';
const MAX = 10 * 1024 * 1024;
const humanSize = (n: number) => (n >= 1_048_576 ? `${(n / 1_048_576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

/**
 * Pictures and documents for a property or project: drop files or pick them, see progress and per-file errors, mark the
 * cover, delete. Files go up one at a time so one bad file never hides the others' results.
 */
export function FilesPanel({
  files,
  upload,
  canEdit,
  coverId,
  onSetCover,
  invalidate,
}: {
  files: FileItem[];
  upload: (f: File) => Promise<unknown>;
  canEdit: boolean;
  coverId?: string | null;
  onSetCover?: (id: string) => Promise<unknown>;
  invalidate: () => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState<{ done: number; total: number } | null>(null);
  const [errors, setErrors] = useState<string[]>([]);
  const remove = useMutation({
    mutationFn: filesApi.remove,
    onSuccess: () => { invalidate(); void qc.invalidateQueries({ queryKey: ['properties'] }); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t delete the file'),
  });

  const send = async (list: FileList | File[]) => {
    const picked = [...list];
    if (!picked.length) return;
    setErrors([]);
    setBusy({ done: 0, total: picked.length });
    const problems: string[] = [];
    for (const [i, f] of picked.entries()) {
      try {
        if (f.size > MAX) throw new Error('is larger than 10 MB');
        await upload(f);
      } catch (e) {
        problems.push(`${f.name} ${e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : e instanceof Error ? e.message : 'could not be uploaded'}`.replace(/\.$/, ''));
      }
      setBusy({ done: i + 1, total: picked.length });
    }
    setBusy(null);
    setErrors(problems);
    invalidate();
    void qc.invalidateQueries({ queryKey: ['properties'] });
    if (problems.length < picked.length) toast.success(`${picked.length - problems.length} file${picked.length - problems.length === 1 ? '' : 's'} added`);
  };

  return (
    <Box>
      {canEdit && (
        <Box
          role="button"
          tabIndex={0}
          aria-label="Add pictures or documents"
          onClick={() => input.current?.click()}
          onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), input.current?.click())}
          onDragOver={(e) => e.preventDefault()}
          onDrop={(e) => { e.preventDefault(); void send(e.dataTransfer.files); }}
          sx={{ border: 1.5, borderStyle: 'dashed', borderColor: 'divider', borderRadius: 2.5, p: 2, textAlign: 'center', cursor: 'pointer', mb: 1.5, '&:hover, &:focus-visible': { borderColor: 'primary.main' } }}
        >
          <UploadFileOutlinedIcon color="disabled" />
          <Typography variant="body2">Drop pictures or PDFs here, or click to choose</Typography>
          <Typography variant="caption" color="text.secondary">JPEG, PNG, WebP or PDF · up to 10 MB each</Typography>
          <input ref={input} type="file" accept={ACCEPT} multiple hidden data-testid="file-input" onChange={(e) => { void send(e.target.files ?? []); e.target.value = ''; }} />
        </Box>
      )}
      {busy && <LinearProgress variant="determinate" value={(busy.done / busy.total) * 100} sx={{ mb: 1.5 }} aria-label="Upload progress" />}
      {errors.map((m) => <Typography key={m} variant="body2" color="error" role="alert">{m}</Typography>)}
      {files.length === 0 ? (
        <Typography variant="body2" color="text.secondary">No files yet.</Typography>
      ) : (
        <Box sx={{ display: 'grid', gap: 1.25, gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))' }}>
          {files.map((f) => (
            <Box key={f.id} sx={{ position: 'relative' }}>
              {f.kind === 'image' ? (
                <Box component="a" href={fileUrl(f.id)} target="_blank" rel="noreferrer" aria-label={`Open ${f.name}`} sx={{ display: 'block' }}><PropertyImage fileId={f.id} alt={f.name} ratio="4 / 3" radius={1.5} /></Box>
              ) : (
                <Box component="a" href={fileUrl(f.id)} target="_blank" rel="noreferrer" sx={{ aspectRatio: '4 / 3', borderRadius: 1.5, bgcolor: 'surface.sunken', display: 'grid', placeItems: 'center', color: 'text.secondary', textDecoration: 'none' }} aria-label={`Open ${f.name}`}><PictureAsPdfOutlinedIcon sx={{ fontSize: 36 }} /></Box>
              )}
              <Typography variant="caption" noWrap component="div" sx={{ mt: 0.5 }} title={f.name}>{f.name} · {humanSize(f.sizeBytes)}</Typography>
              {f.kind === 'image' && coverId === f.id && <Chip size="small" color="primary" label="Cover" sx={{ position: 'absolute', top: 6, left: 6 }} />}
              {canEdit && (
                <Stack direction="row" sx={{ position: 'absolute', top: 2, right: 2, bgcolor: 'background.paper', borderRadius: 2, boxShadow: 1 }}>
                  {f.kind === 'image' && onSetCover && coverId !== f.id && (
                    <Tooltip title="Make cover"><IconButton size="small" aria-label={`Make ${f.name} the cover`} onClick={() => void onSetCover(f.id).then(invalidate)}><StarBorderRoundedIcon fontSize="small" /></IconButton></Tooltip>
                  )}
                  {coverId === f.id && <StarRoundedIcon fontSize="small" color="primary" sx={{ m: 0.75 }} aria-hidden />}
                  <Tooltip title="Delete"><IconButton size="small" aria-label={`Delete ${f.name}`} onClick={async () => { if (await confirm({ title: `Delete ${f.name}?`, confirmLabel: 'Delete', destructive: true })) remove.mutate(f.id); }}><DeleteOutlineRoundedIcon fontSize="small" /></IconButton></Tooltip>
                </Stack>
              )}
            </Box>
          ))}
        </Box>
      )}
    </Box>
  );
}
