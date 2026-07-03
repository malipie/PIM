import { useOne, useUpdate } from '@refinedev/core';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/toast';

import { ChannelForm, type ChannelFormValues } from './form';

interface ChannelDetail {
  id: string;
  code: string;
  name?: string | null;
  categoryTreeRootId?: string | null;
}

export function ChannelEditPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const toast = useToast();
  const { id } = useParams<{ id: string }>();

  const { result: channel, query } = useOne<ChannelDetail>({
    resource: 'channels',
    id: id ?? '',
  });
  const { mutate: doUpdate, mutation } = useUpdate();

  if (query.isLoading || !channel) {
    return <p className="text-sm text-muted-foreground">{t('app.loading')}</p>;
  }

  const defaultValues: Partial<ChannelFormValues> = {
    code: channel.code,
    name: channel.name ?? '',
  };

  const headerTitle = `${t('channels.edit.title_prefix')} ${channel.name ?? channel.code}`;

  const handleSubmit = (values: ChannelFormValues) => {
    doUpdate(
      {
        resource: 'channels',
        id: channel.id,
        values: {
          name: values.name,
        },
      },
      {
        onSuccess: () => {
          toast.success(t('channels.edit.success'));
          navigate(`/modeling/channels/${channel.id}`);
        },
        onError: () => {
          toast.error(t('channels.edit.error'));
        },
      },
    );
  };

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <Button asChild variant="ghost" size="sm" className="-ml-3">
          <Link to={`/modeling/channels/${channel.id}`}>
            <ArrowLeft className="size-4" />
            {t('channels.back')}
          </Link>
        </Button>
        <h1 className="text-2xl font-semibold tracking-tight">{headerTitle}</h1>
        <p className="font-mono text-xs text-muted-foreground">{channel.code}</p>
      </div>

      <ChannelForm
        mode="edit"
        defaultValues={defaultValues}
        isSubmitting={mutation.isPending}
        onSubmit={handleSubmit}
        onCancel={() => navigate(`/modeling/channels/${channel.id}`)}
      />
    </div>
  );
}
