import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Alert, AlertTitle, Box, Button, Stack } from '@mui/material';

/**
 * Route-level safety net: a render error in one page must not blank the whole app.
 * Resets automatically when `resetKey` changes (e.g. the user navigates elsewhere).
 */
interface Props {
  children: ReactNode;
  resetKey?: string;
}
interface State {
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Reported to the console (and to the platform log shipper via `window.onerror` hooks in telemetry).
    console.error('UI render error', error, info.componentStack);
  }

  componentDidUpdate(prev: Props) {
    if (this.state.error && prev.resetKey !== this.props.resetKey) this.setState({ error: null });
  }

  render() {
    if (!this.state.error) return this.props.children;
    return (
      <Box sx={{ maxWidth: 560, mx: 'auto', mt: 8 }}>
        <Alert severity="error" sx={{ alignItems: 'flex-start' }}>
          <AlertTitle>This page hit a problem</AlertTitle>
          Your data is safe. Reloading usually fixes it; if it keeps happening, tell your admin what you were doing.
          <Stack direction="row" spacing={1} sx={{ mt: 1.5 }}>
            <Button size="small" variant="contained" onClick={() => window.location.reload()}>Reload page</Button>
            <Button size="small" onClick={() => this.setState({ error: null })}>Try again</Button>
          </Stack>
        </Alert>
      </Box>
    );
  }
}
