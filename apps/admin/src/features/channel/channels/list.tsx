import { useDelete, useList } from '@refinedev/core';
import { Eye, MoreHorizontal, Pencil, Plus, Radio, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useToast } from '@/components/ui/toast';

import { ChannelDeleteConfirmDialog } from './delete-confirm-dialog';

export interface ChannelRow {
  id: string;
  code: string;
  name?: string | null;
  categoryTreeRootId?: string | null;
}

export function ChannelsListPage() {
  const { t } = useTranslation();
  const { result, query } = useList<ChannelRow>({
    resource: 'channels',
    pagination: { mode: 'off' },
  });
  const { mutate: doDelete } = useDelete();
  const toast = useToast();

  const [pendingDelete, setPendingDelete] = useState<ChannelRow | null>(null);

  const channels = result.data;
  const isLoading = query.isLoading;

  const handleDelete = (channel: ChannelRow) => {
    doDelete(
      { resource: 'channels', id: channel.id },
      {
        onSuccess: () => {
          toast.success(t('channels.delete.success'));
          setPendingDelete(null);
        },
        onError: () => {
          toast.error(t('channels.delete.error'));
        },
      },
    );
  };

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t('channels.list_title')}</h1>
          <p className="text-sm text-muted-foreground">{t('channels.list_subtitle')}</p>
        </div>
        <Button asChild>
          <Link to="/modeling/channels/new">
            <Plus className="size-4" />
            {t('channels.list.create_button')}
          </Link>
        </Button>
      </div>

      <div className="rounded-xl border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-[180px]">{t('channels.fields.code')}</TableHead>
              <TableHead>{t('channels.fields.name')}</TableHead>
              <TableHead className="w-[120px] text-right">
                <span className="sr-only">{t('channels.fields.actions')}</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableRow>
                <TableCell colSpan={3} className="py-10 text-center text-muted-foreground">
                  {t('app.loading')}
                </TableCell>
              </TableRow>
            ) : channels.length === 0 ? (
              <TableRow>
                <TableCell colSpan={3} className="py-10 text-center text-muted-foreground">
                  {t('channels.list.empty')}
                </TableCell>
              </TableRow>
            ) : (
              channels.map((row) => (
                <TableRow key={row.id}>
                  <TableCell className="font-mono text-xs">
                    <Radio className="mr-1 inline size-3.5 text-muted-foreground" />
                    {row.code}
                  </TableCell>
                  <TableCell className="font-medium">{row.name ?? row.code}</TableCell>
                  <TableCell className="text-right">
                    <div className="flex items-center justify-end gap-1">
                      <Button asChild variant="ghost" size="sm">
                        <Link to={`/modeling/channels/${row.id}`}>
                          <Eye className="size-4" />
                          <span className="sr-only">{t('channels.actions.view')}</span>
                        </Link>
                      </Button>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="sm">
                            <MoreHorizontal className="size-4" />
                            <span className="sr-only">{t('channels.fields.actions')}</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem asChild>
                            <Link to={`/modeling/channels/${row.id}/edit`}>
                              <Pencil className="size-4" />
                              {t('channels.list.actions.edit')}
                            </Link>
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            onSelect={(event) => {
                              event.preventDefault();
                              setPendingDelete(row);
                            }}
                            className="text-destructive focus:text-destructive"
                          >
                            <Trash2 className="size-4" />
                            {t('channels.list.actions.delete')}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {pendingDelete ? (
        <ChannelDeleteConfirmDialog
          channelLabel={pendingDelete.name ?? pendingDelete.code}
          open={true}
          onClose={() => setPendingDelete(null)}
          onConfirm={() => handleDelete(pendingDelete)}
        />
      ) : null}
    </div>
  );
}
