import { useRef, useState } from 'react';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, LinearProgress, MenuItem, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi, type ImportJob, type LeadSource } from '@/api/crm';
import { SOURCE_LABEL } from './shared';

const MAX_BYTES = 5 * 1024 * 1024;
const TEMPLATE = 'Name,Phone,Email,Source,Budget,Location,Tags,Notes,Owner email\r\nMeera Iyer,+91 98450 12345,meera@example.com,Facebook,1.5 cr,Whitefield; Koramangala,vip;nri,Wants a 3BHK,\r\n';

function downloadTemplate() {
  const url = URL.createObjectURL(new Blob([TEMPLATE], { type: 'text/csv' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = 'anibros-lead-import-template.csv';
  a.click();
  URL.revokeObjectURL(url);
}

/** Upload a CSV, watch it import in the background, see exactly which rows were rejected and why. */
export function ImportDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const fileInput = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<{ name: string; text: string } | null>(null);
  const [onDuplicate, setOnDuplicate] = useState<'skip' | 'create'>('skip');
  const [defaultSource, setDefaultSource] = useState<LeadSource>('other');
  const [jobId, setJobId] = useState<string | null>(null);
  const [error, setError] = useState<{ message: string; columns?: string } | null>(null);

  const jobs = useQuery({
    queryKey: ['leads', 'imports'],
    queryFn: leadsApi.imports,
    enabled: open && Boolean(jobId),
    refetchInterval: (q) => (q.state.data?.find((j) => j.id === jobId)?.status === 'completed' || q.state.data?.find((j) => j.id === jobId)?.status === 'failed' ? false : 1500),
  });
  const job: ImportJob | undefined = jobs.data?.find((j) => j.id === jobId);
  const finished = job?.status === 'completed' || job?.status === 'failed';

  const start = useMutation({
    mutationFn: () => leadsApi.startImport({ fileName: file!.name, csv: file!.text, onDuplicate, defaultSource }),
    onSuccess: (j) => {
      setJobId(j.id);
      void qc.invalidateQueries({ queryKey: ['leads', 'imports'] });
    },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setError({ message: 'Couldn’t start the import. Please try again.' });
      setError({ message: e.errors[0]?.message ?? e.detail, columns: e.status === 400 && e.errors[0]?.code?.startsWith('missing') ? e.detail : undefined });
    },
  });

  const reset = () => {
    setFile(null);
    setJobId(null);
    setError(null);
    start.reset();
  };
  const close = () => {
    if (finished && job?.createdCount) void qc.invalidateQueries({ queryKey: ['leads'] });
    reset();
    onClose();
  };

  const pick = async (f: File | undefined) => {
    setError(null);
    if (!f) return;
    if (f.size > MAX_BYTES) return setError({ message: 'That file is over 5 MB. Split it into parts of about 40,000 rows and import them one by one.' });
    if (!/\.(csv|txt)$/i.test(f.name) && f.type && !f.type.includes('csv') && !f.type.includes('text')) return setError({ message: 'Choose a .csv file. In Excel: File → Save As → CSV.' });
    setFile({ name: f.name, text: await f.text() });
  };

  const pct = job && job.totalRows ? Math.round((job.processedRows / job.totalRows) * 100) : 0;

  return (
    <Dialog open={open} onClose={start.isPending ? undefined : close} fullWidth maxWidth="sm" aria-labelledby="import-title">
      <DialogTitle id="import-title">Import leads from CSV</DialogTitle>
      <DialogContent>
        {!jobId ? (
          <Stack spacing={2} sx={{ pt: 0.5 }}>
            <Typography variant="body2" color="text.secondary">
              One row per enquiry. We recognise columns like name, phone, email, source, budget, location, tags, notes and owner email.
              People already in your contacts are matched by phone or email instead of duplicated.
            </Typography>
            {error && (
              <Alert severity="error">
                {error.message}
                {error.columns && <Typography variant="caption" sx={{ display: "block" }}>{error.columns}</Typography>}
              </Alert>
            )}
            <Box
              role="button"
              tabIndex={0}
              onClick={() => fileInput.current?.click()}
              onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), fileInput.current?.click())}
              onDragOver={(e) => e.preventDefault()}
              onDrop={(e) => { e.preventDefault(); void pick(e.dataTransfer.files[0]); }}
              sx={{ border: 1.5, borderStyle: 'dashed', borderColor: file ? 'primary.main' : 'divider', borderRadius: 2.5, p: 3, textAlign: 'center', cursor: 'pointer', bgcolor: 'surface.sunken', '&:hover, &:focus-visible': { borderColor: 'primary.main' } }}
            >
              <UploadFileOutlinedIcon color={file ? 'primary' : 'disabled'} />
              <Typography variant="subtitle2" sx={{ mt: 0.5 }}>{file ? file.name : 'Choose a CSV file or drop it here'}</Typography>
              <Typography variant="caption" color="text.secondary">{file ? `${(file.text.split(/\r?\n/).length - 1).toLocaleString()} lines · click to choose another` : 'Up to 5 MB'}</Typography>
              <input ref={fileInput} type="file" accept=".csv,text/csv,text/plain" hidden onChange={(e) => { void pick(e.target.files?.[0]); e.target.value = ''; }} data-testid="import-file" />
            </Box>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField select label="If the person already has an open lead" fullWidth value={onDuplicate} onChange={(e) => setOnDuplicate(e.target.value as 'skip' | 'create')}>
                <MenuItem value="skip">Skip the row</MenuItem>
                <MenuItem value="create">Create another lead</MenuItem>
              </TextField>
              <TextField select label="Source when a row has none" fullWidth value={defaultSource} onChange={(e) => setDefaultSource(e.target.value as LeadSource)}>
                {Object.entries(SOURCE_LABEL).map(([k, v]) => <MenuItem key={k} value={k}>{v}</MenuItem>)}
              </TextField>
            </Stack>
            <Button size="small" onClick={downloadTemplate} sx={{ alignSelf: 'flex-start' }}>Download a template</Button>
          </Stack>
        ) : (
          <Stack spacing={2} sx={{ pt: 0.5 }} aria-live="polite">
            <Box>
              <Stack direction="row" sx={{ justifyContent: 'space-between', mb: 0.75 }}>
                <Typography variant="subtitle2">{job?.fileName ?? file?.name}</Typography>
                <Typography variant="body2" color="text.secondary">{job?.status === 'completed' ? 'Done' : job?.status === 'failed' ? 'Failed' : job?.status === 'running' ? `${pct}%` : 'Waiting for a worker…'}</Typography>
              </Stack>
              <LinearProgress variant={job?.status === 'queued' || !job ? 'indeterminate' : 'determinate'} value={pct} color={job?.status === 'failed' ? 'error' : 'primary'} aria-label="Import progress" />
            </Box>
            {job && (
              <Stack direction="row" spacing={3} sx={{ flexWrap: 'wrap', rowGap: 1 }}>
                {[['Leads created', job.createdCount], ['Matched existing people', job.matchedCount], ['Skipped duplicates', job.skippedCount], ['Rows with problems', job.errorCount]].map(([label, n]) => (
                  <Box key={label as string}>
                    <Typography variant="h3" component="p" sx={{ fontVariantNumeric: 'tabular-nums' }}>{(n as number).toLocaleString()}</Typography>
                    <Typography variant="caption" color="text.secondary">{label}</Typography>
                  </Box>
                ))}
              </Stack>
            )}
            {job && job.errors.length > 0 && (
              <Box>
                <Typography variant="subtitle2" sx={{ mb: 0.5 }}>Rows that were not imported{job.errorCount > job.errors.length ? ` (first ${job.errors.length} of ${job.errorCount})` : ''}</Typography>
                <Box sx={{ maxHeight: 220, overflow: 'auto', border: 1, borderColor: 'divider', borderRadius: 2 }}>
                  <Table size="small" stickyHeader aria-label="Import problems">
                    <TableHead><TableRow><TableCell sx={{ width: 64 }}>Row</TableCell><TableCell>Problem</TableCell></TableRow></TableHead>
                    <TableBody>
                      {job.errors.map((e, i) => (
                        <TableRow key={`${e.row}-${i}`}>
                          <TableCell sx={{ fontVariantNumeric: 'tabular-nums' }}>{e.row || '—'}</TableCell>
                          <TableCell>{e.message}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </Box>
                <Typography variant="caption" color="text.secondary">Row numbers match your spreadsheet (the header is row 1). Fix those rows and import the file again — rows that worked are matched, not duplicated.</Typography>
              </Box>
            )}
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        {!jobId ? (
          <>
            <Button onClick={close} disabled={start.isPending}>Cancel</Button>
            <Button variant="contained" disabled={!file || start.isPending} onClick={() => { setError(null); start.mutate(); }}>{start.isPending ? 'Uploading…' : 'Start import'}</Button>
          </>
        ) : (
          <>
            {finished && <Button onClick={reset}>Import another file</Button>}
            <Button variant={finished ? 'contained' : 'text'} onClick={close}>{finished ? 'Done' : 'Close — it keeps running'}</Button>
          </>
        )}
      </DialogActions>
    </Dialog>
  );
}
