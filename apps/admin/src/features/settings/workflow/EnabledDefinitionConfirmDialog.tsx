import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

interface EnabledDefinitionConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}

/**
 * Saving an ENABLED definition changes the transitions available on live
 * objects the moment it persists — make the operator confirm explicitly.
 */
export function EnabledDefinitionConfirmDialog({
  open,
  onOpenChange,
  onConfirm,
}: EnabledDefinitionConfirmDialogProps) {
  const { t } = useTranslation();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t('settings.workflow.confirm_title', { defaultValue: 'Definicja jest włączona' })}
          </DialogTitle>
          <DialogDescription>
            {t('settings.workflow.confirm_body', {
              defaultValue:
                'Ta definicja steruje żywymi obiektami — zapis zmienia dostępne przejścia od razu. Kontynuować?',
            })}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel', { defaultValue: 'Anuluj' })}
          </Button>
          <Button onClick={onConfirm} data-testid="definition-confirm-save">
            {t('settings.workflow.confirm_cta', { defaultValue: 'Zapisz mimo to' })}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
