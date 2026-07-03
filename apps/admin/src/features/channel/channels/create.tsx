import { useCreate } from '@refinedev/core';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';

import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ui/toast';

import { ChannelForm, type ChannelFormValues } from './form';

interface CreatedChannel {
  id: string;
}

export function ChannelCreatePage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const toast = useToast();
  const { mutate: doCreate, mutation } = useCreate<CreatedChannel>();

  const handleSubmit = (values: ChannelFormValues) => {
    doCreate(
      {
        resource: 'channels',
        values: {
          code: values.code,
          name: values.name,
        },
      },
      {
        onSuccess: (response) => {
          toast.success(t('channels.create.success'));
          const created = response.data;
          if (created?.id) {
            navigate(`/modeling/channels/${created.id}`);
          } else {
            navigate('/modeling/channels');
          }
        },
        onError: () => {
          toast.error(t('channels.create.error'));
        },
      },
    );
  };

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <Button asChild variant="ghost" size="sm" className="-ml-3">
          <Link to="/modeling/channels">
            <ArrowLeft className="size-4" />
            {t('channels.back')}
          </Link>
        </Button>
        <h1 className="text-2xl font-semibold tracking-tight">{t('channels.create.title')}</h1>
      </div>

      <ChannelForm
        mode="create"
        isSubmitting={mutation.isPending}
        onSubmit={handleSubmit}
        onCancel={() => navigate('/modeling/channels')}
      />
    </div>
  );
}
