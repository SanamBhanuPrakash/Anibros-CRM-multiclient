import { useCallback, useRef, useState, type ReactNode } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi, type Lead, type ReasonKind } from '@/api/crm';
import { useToast } from '@/ui/toast';
import { FIELD_LABEL, usePipelines } from './shared';
import { SaleDialog, type SaleResult } from '@/features/deals/SaleDialog';
import { invalidateDeals, problemText } from '@/features/deals/shared';
import { ReasonDialog, useReasonCodeRequired, type ReasonResult } from './ReasonDialog';

export interface MoveTarget {
  stageId: string;
  name: string;
  /** Set when the stage closes a lead as lost or not a fit: a reason is mandatory. */
  kind: ReasonKind | null;
}

/**
 * One way to move a lead between stages for the whole app (board drag, stage buttons, menus):
 *  - asks why only when the destination closes the lead as Lost or Not a fit (a fixed list of reasons plus an optional note),
 *  - turns the server's rule failures (transition not allowed, required fields, stale version) into plain sentences,
 *  - refreshes every lead view afterwards.
 * `dialog` must be rendered once by the caller.
 */
export function useLeadMover(opts: { onMoved?: (lead: Lead) => void } = {}) {
  const qc = useQueryClient();
  const toast = useToast();
  const [pending, setPending] = useState<{ lead: Pick<Lead, 'id' | 'contactName'>; target: MoveTarget } | null>(null);
  const [sale, setSale] = useState<{ lead: Pick<Lead, 'id' | 'contactName'>; target: MoveTarget } | null>(null);
  const [saleError, setSaleError] = useState<string | null>(null);
  const pipelines = usePipelines();
  /** Moving into a Won stage of a pipeline that requires a deal value means recording the sale at the same time (decision D3). */
  const needsDeal = useCallback(
    (stageId: string) => {
      const p = pipelines.data?.find((x) => x.stages.some((s) => s.id === stageId));
      return Boolean(p?.dealValueRequired && p.stages.find((s) => s.id === stageId)?.mapsToStatus === 'won');
    },
    [pipelines.data]
  );
  const resolver = useRef<((ok: boolean) => void) | null>(null);
  const codeRequired = useReasonCodeRequired(pending?.target.stageId);

  const mutation = useMutation({
    mutationFn: (v: { id: string; stageId: string; deal?: SaleResult } & ReasonResult) => leadsApi.move(v.id, { stageId: v.stageId, lossReason: v.lossReason, reasonCode: v.reasonCode, deal: v.deal }),
    onSuccess: (lead) => {
      setSale(null);
      invalidateDeals(qc);
      void qc.invalidateQueries({ queryKey: ['pipelines'] });
      toast.success(`${lead.contactName ?? 'Lead'} moved to ${lead.stageName}`);
      opts.onMoved?.(lead);
      resolver.current?.(true);
    },
    onError: (e, v) => {
      if (v.deal) {
        // the sale dialog stays open and says what the server said (e.g. the unit is reserved for someone else)
        setSaleError(problemText(e, 'Couldn’t record the sale. Please try again.'));
        void qc.invalidateQueries({ queryKey: ['leads'] });
        return;
      }
      const p = e instanceof ApiProblem ? e : null;
      const missing = p?.extra.missing as string[] | undefined;
      toast.error(
        p?.code === 'INVALID_TRANSITION'
          ? p.detail
          : p?.extra.reason === 'missing_required_fields' && missing
            ? `Add ${missing.map((m) => FIELD_LABEL[m] ?? m).join(', ')} first, then move it.`
            : p?.extra.reason === 'deal_value_required'
              ? 'Record the sale (the property and its final value) to mark this lead as won.'
            : (p?.errors[0]?.message ?? p?.detail ?? 'Couldn’t move the lead. Please try again.')
      );
      void qc.invalidateQueries({ queryKey: ['leads'] }); // a rejected optimistic move must snap back
      resolver.current?.(false);
    },
  });

  /** Resolves true when the move happened, false when cancelled or rejected. */
  const move = useCallback(
    (lead: Pick<Lead, 'id' | 'contactName'>, target: MoveTarget) =>
      new Promise<boolean>((resolve) => {
        resolver.current = resolve;
        if (target.kind) setPending({ lead, target });
        else if (needsDeal(target.stageId)) {
          setSaleError(null);
          setSale({ lead, target });
        } else mutation.mutate({ id: lead.id, stageId: target.stageId });
      }),
    [mutation, needsDeal]
  );

  const close = (ok: boolean) => {
    setPending(null);
    if (!ok) resolver.current?.(false);
  };

  const closeSale = () => {
    setSale(null);
    resolver.current?.(false);
  };

  const dialog: ReactNode = (
    <>
    <SaleDialog
      open={Boolean(sale)}
      leadId={sale?.lead.id ?? ''}
      leadName={sale?.lead.contactName ?? 'this lead'}
      targetName={sale?.target.name ?? 'Won'}
      busy={mutation.isPending}
      error={saleError}
      onCancel={closeSale}
      onConfirm={(deal) => {
        if (!sale) return;
        setSaleError(null);
        mutation.mutate({ id: sale.lead.id, stageId: sale.target.stageId, deal });
      }}
    />
    <ReasonDialog
      open={Boolean(pending)}
      title={`Move to ${pending?.target.name ?? ''}`}
      subject={pending?.lead.contactName ?? 'this lead'}
      targetName={pending?.target.name ?? ''}
      kind={pending?.target.kind ?? 'lost'}
      codeRequired={codeRequired}
      busy={mutation.isPending}
      onCancel={() => close(false)}
      onConfirm={(r) => {
        if (!pending) return;
        mutation.mutate({ id: pending.lead.id, stageId: pending.target.stageId, ...r });
        close(true);
      }}
    />
    </>
  );

  return { move, dialog, isMoving: mutation.isPending };
}
